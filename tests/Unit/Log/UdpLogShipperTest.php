<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Core\Log\AsyncJsonLogger;
use Semitexa\Core\Log\UdpLogShipper;

/**
 * LOG_REMOTE: the logger had no remote sink of its own; a syslog route was
 * dropped because its socket held the worker. A datagram never waits.
 */
final class UdpLogShipperTest extends TestCase
{
    /** @var resource */
    private $server;
    private string $address;
    private string $stderr;

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['LOG_REMOTE', 'LOG_SINK', 'LOG_LEVEL', 'LOG_CHANNELS'] as $key) {
            $this->saved[$key] = getenv($key);
        }
        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        self::assertIsResource($server, (string) $error);
        $this->server = $server;
        $this->address = 'udp://' . stream_socket_get_name($server, false);
        $this->stderr = sys_get_temp_dir() . '/udp-log-shipper-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            $value === false ? putenv($key) : putenv($key . '=' . $value);
        }
        fclose($this->server);
        @unlink($this->stderr);
    }

    #[Test]
    public function every_written_line_also_goes_out_as_one_datagram(): void
    {
        $logger = $this->logger();

        $logger->warning('disk almost full', ['_channel' => 'storage', 'free' => '2%']);
        $logger->error('payment declined', ['order' => 7]);
        $logger->warning('not routed', ['_channel' => 'noisy']);
        $logger->flush();

        $first = json_decode($this->receive(), true);
        $second = json_decode($this->receive(), true);
        self::assertSame(['disk almost full', 'storage'], [$first['message'] ?? null, $first['channel'] ?? null]);
        self::assertSame('payment declined', $second['message'] ?? null);
        self::assertSame('', $this->receive(), 'a channel routed nowhere is not sent either');
    }

    #[Test]
    public function an_entry_too_large_for_a_datagram_goes_as_a_stub_that_says_so(): void
    {
        $logger = $this->logger();

        $logger->error('huge', ['dump' => str_repeat('x', UdpLogShipper::MAX_DATAGRAM + 10)]);
        $logger->flush();

        $stub = json_decode($this->receive(), true);
        self::assertSame('huge', $stub['message'] ?? null);
        self::assertStringContainsString('over what one datagram carries', (string) ($stub['truncated'] ?? ''));
    }

    #[Test]
    public function only_a_udp_address_is_taken(): void
    {
        // A host name would be looked up on the first send; the coroutine
        // runtime opens udp:// as IPv4 whatever the address.
        foreach (['', 'tcp://10.0.0.1:514', 'udp://host', 'udp://collector.internal:5140', 'udp://[::1]:5140', 'udp://10.0.0.1:99999', 'udp://999.0.0.1:514', '10.0.0.1:514'] as $value) {
            $rejected = [];
            self::assertNull(UdpLogShipper::fromEnv($value, $rejected), $value);
        }
        self::assertInstanceOf(UdpLogShipper::class, UdpLogShipper::fromEnv('udp://10.0.0.5:5140'));
    }

    private function logger(): AsyncJsonLogger
    {
        putenv('LOG_REMOTE=' . $this->address);
        putenv('LOG_SINK=stderr');
        putenv('LOG_LEVEL=debug');
        putenv('LOG_CHANNELS=noisy=off');
        $logger = new AsyncJsonLogger();
        (new \ReflectionProperty($logger, 'environment'))->setValue($logger, (new \ReflectionClass(Environment::class))->newInstanceWithoutConstructor());
        (new \ReflectionProperty($logger, 'stderrTarget'))->setValue($logger, $this->stderr);

        return $logger;
    }

    /** Lines a closed port refused are counted into the next line that gets through. */
    #[Test]
    public function the_next_line_through_says_how_many_were_lost(): void
    {
        $closed = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        self::assertIsResource($closed);
        $address = 'udp://' . stream_socket_get_name($closed, false);
        fclose($closed);
        $shipper = UdpLogShipper::fromEnv($address);
        self::assertNotNull($shipper);
        for ($i = 0; $i < 4; $i++) {
            $shipper->ship('{"message":"lost ' . $i . '"}', []);
        }
        $dropped = (new \ReflectionProperty(UdpLogShipper::class, 'dropped'))->getValue($shipper);
        self::assertGreaterThan(0, $dropped, 'the port answered "unreachable" to some of them');

        // The collector comes back on that port.
        $back = stream_socket_server($address, $errno, $error, STREAM_SERVER_BIND);
        self::assertIsResource($back, (string) $error);
        fclose($this->server);
        $this->server = $back;
        $shipper->ship('{"message":"through"}', []);

        $line = json_decode($this->receive(), true);
        self::assertSame('through', $line['message'] ?? null);
        self::assertSame($dropped, $line['remote_dropped'] ?? null);
    }

    private function receive(): string
    {
        $read = [$this->server];
        $none = null;
        if (stream_select($read, $none, $none, 0, 300_000) !== 1) {
            return '';
        }

        return (string) stream_socket_recvfrom($this->server, 65535);
    }
}
