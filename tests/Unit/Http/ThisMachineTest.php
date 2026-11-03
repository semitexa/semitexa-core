<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\ThisMachine;
use Semitexa\Core\Request;

/**
 * Who may reach a developer surface that answers without a sign-in. The
 * peers are the ones measured on the dev stack 2026-11-03: the host (direct
 * and through the local router) arrives from the bridge gateway 172.18.0.1,
 * a request to the laptop's Wi-Fi address keeps that address.
 */
final class ThisMachineTest extends TestCase
{
    private string $routeFile = '';

    private string|false $gateways = false;

    protected function setUp(): void
    {
        // An empty process value, not an unset one: unset, a DEV_LOCAL_GATEWAYS
        // in the project's .env would answer instead and change the cases.
        $this->gateways = getenv('DEV_LOCAL_GATEWAYS');
        putenv('DEV_LOCAL_GATEWAYS=');
        $this->routeFile = (string) tempnam(sys_get_temp_dir(), 'route');
        file_put_contents($this->routeFile, "Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\n"
            . "eth0\t00000000\t010012AC\t0003\t0\t0\t0\t00000000\n"
            . "eth0\t000012AC\t00000000\t0001\t0\t0\t0\t0000FFFF\n");
        ThisMachine::useRouteFile($this->routeFile);
        // In a container, as the image runs; the host case sets its own.
        ThisMachine::useContainerMarker($this->routeFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->routeFile);
        ThisMachine::useRouteFile('/proc/net/route');
        ThisMachine::useContainerMarker('/.dockerenv');
        putenv($this->gateways === false ? 'DEV_LOCAL_GATEWAYS' : 'DEV_LOCAL_GATEWAYS=' . $this->gateways);
    }

    /** @return iterable<string, array{string, array<string, string>, bool}> */
    public static function requests(): iterable
    {
        yield 'curl inside the container' => ['127.0.0.1', ['Host' => 'localhost:9507'], true];
        yield 'a browser on the host, localhost:<port>' => ['172.18.0.1', ['Host' => 'localhost:9507'], true];
        yield 'the local router, a *.test name' => ['172.18.0.1', ['Host' => 'dev.semitexa.test', 'X-Real-Ip' => '172.23.0.1', 'X-Forwarded-For' => '172.23.0.1'], true];
        yield 'a dual-stack listener maps the gateway' => ['::ffff:172.18.0.1', ['Host' => '127.0.0.1:9507'], true];
        yield 'IPv6 loopback' => ['::1', ['Host' => '[::1]:9507'], true];
        yield 'a *.localhost name' => ['172.18.0.1', ['Host' => 'app.localhost:9507'], true];
        yield 'no peer: built in-process' => ['', ['Host' => 'localhost'], true];

        yield 'another machine on the Wi-Fi, straight to the port' => ['192.168.100.7', ['Host' => '192.168.100.2:9507'], false];
        yield 'another machine sending a local Host' => ['192.168.100.7', ['Host' => 'dev.semitexa.test'], false];
        yield 'another machine through a router listening on every interface' => ['172.18.0.1', ['Host' => 'dev.semitexa.test', 'X-Real-Ip' => '192.168.100.7', 'X-Forwarded-For' => '192.168.100.7'], false];
        yield 'a client prepending loopback to the chain' => ['172.18.0.1', ['Host' => 'dev.semitexa.test', 'X-Forwarded-For' => '127.0.0.1, 192.168.100.7'], false];
        yield 'a router hop naming a LAN host numbered from 172.16/12' => ['172.18.0.1', ['Host' => 'dev.semitexa.test', 'X-Forwarded-For' => '172.20.1.9'], false];
        yield 'RFC 7239 Forwarded naming another machine' => ['172.18.0.1', ['Host' => 'dev.semitexa.test', 'Forwarded' => 'for=192.168.100.7;proto=http'], false];
        yield 'RFC 7239 Forwarded whose only for is part of another name' => ['172.18.0.1', ['Host' => 'dev.semitexa.test', 'Forwarded' => 'notfor=127.0.0.1'], false];
        yield 'RFC 7239 Forwarded with for after another parameter' => ['172.18.0.1', ['Host' => 'dev.semitexa.test', 'Forwarded' => 'proto=http;for=127.0.0.1'], true];
        yield 'RFC 7239 Forwarded: a second element names another machine' => ['172.18.0.1', ['Host' => 'dev.semitexa.test', 'Forwarded' => 'for=127.0.0.1, for=192.168.100.7'], false];
        yield 'RFC 7239 Forwarded with an obfuscated hop' => ['172.18.0.1', ['Host' => 'dev.semitexa.test', 'Forwarded' => 'for=_hidden'], false];
        yield 'DNS rebinding: a public name pointed at loopback' => ['172.18.0.1', ['Host' => 'rebind.evil.example:9507'], false];
        yield 'the laptop calling its own Wi-Fi address' => ['192.168.100.2', ['Host' => '192.168.100.2:9507'], false];
        yield 'no Host header from loopback (HTTP/1.0, a raw socket)' => ['127.0.0.1', [], true];
        yield 'no Host header from another machine' => ['192.168.100.7', [], false];
        yield 'a container on the bridge that is not the gateway' => ['172.18.0.9', ['Host' => 'localhost'], false];
    }

    /** @param array<string, string> $headers */
    #[Test]
    #[DataProvider('requests')]
    public function decides_whether_this_machine_sent_the_request(string $peer, array $headers, bool $local): void
    {
        $request = new Request('GET', '/__observatory/graph', $headers, [], [], $peer === '' ? [] : ['remote_addr' => $peer], []);

        self::assertSame($local, ThisMachine::sent($request));
    }

    #[Test]
    public function without_a_route_table_only_loopback_is_this_machine(): void
    {
        ThisMachine::useRouteFile($this->routeFile . '.missing');

        self::assertTrue(ThisMachine::sent(new Request('GET', '/', ['Host' => 'localhost'], [], [], ['remote_addr' => '127.0.0.1'], [])));
        self::assertFalse(ThisMachine::sent(new Request('GET', '/', ['Host' => 'localhost'], [], [], ['remote_addr' => '172.18.0.1'], [])));
    }

    #[Test]
    public function a_router_hop_from_a_custom_bridge_gateway_counts_only_when_configured(): void
    {
        $through = static fn (string $hop): bool => ThisMachine::sent(new Request('GET', '/', ['Host' => 'dev.semitexa.test', 'X-Forwarded-For' => $hop], [], [], ['remote_addr' => '172.18.0.1'], []));
        self::assertFalse($through('172.23.1.1'), 'not the x.y.0.1 pattern, and not configured');

        putenv('DEV_LOCAL_GATEWAYS=10.9.0.254, 172.23.1.1, fd00::1');
        self::assertTrue($through('172.23.1.1'));
        self::assertTrue($through('10.9.0.254'));
        self::assertTrue($through('[fd00:0:0:0:0:0:0:1]'), 'an IPv6 gateway is matched by value, not spelling');
        self::assertFalse($through('172.23.1.9'), 'another address on that bridge is still refused');
    }

    #[Test]
    public function a_default_gateway_that_is_a_lan_router_is_another_machine(): void
    {
        // Run on a host, not in the image: the default route leads to the router.
        file_put_contents($this->routeFile, "Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\n"
            . "wlan0\t00000000\t0101A8C0\t0003\t0\t0\t0\t00000000\n");
        ThisMachine::useRouteFile($this->routeFile);
        $fromRouter = new Request('GET', '/', ['Host' => 'localhost:9507'], [], [], ['remote_addr' => '192.168.1.1'], []);

        self::assertFalse(ThisMachine::sent($fromRouter), 'the router is not this machine');

        putenv('DEV_LOCAL_GATEWAYS=192.168.1.1');
        self::assertTrue(ThisMachine::sent($fromRouter), 'unless the operator names it as a local gateway');
    }

    #[Test]
    public function on_a_host_a_router_numbered_like_a_docker_bridge_is_another_machine(): void
    {
        // A LAN on 172.20.0.0/16: its router is 172.20.0.1, the x.y.0.1 a bridge would have.
        file_put_contents($this->routeFile, "Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\n"
            . "eth0\t00000000\t010014AC\t0003\t0\t0\t0\t00000000\n");
        ThisMachine::useRouteFile($this->routeFile);
        $fromRouter = new Request('GET', '/', ['Host' => 'localhost:9507'], [], [], ['remote_addr' => '172.20.0.1'], []);

        ThisMachine::useContainerMarker($this->routeFile . '.absent');
        self::assertFalse(ThisMachine::sent($fromRouter), 'on a host the default gateway is a router');

        ThisMachine::useContainerMarker($this->routeFile);
        self::assertTrue(ThisMachine::sent($fromRouter), 'in a container the same address is the bridge to the host');
    }

    #[Test]
    public function a_container_on_the_host_network_does_not_trust_a_bridge_numbered_router(): void
    {
        // --network host: /.dockerenv exists, but the routes are the host's, docker0 among them.
        file_put_contents($this->routeFile, "Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\n"
            . "eth0\t00000000\t010014AC\t0003\t0\t0\t0\t00000000\n"
            . "docker0\t000011AC\t00000000\t0001\t0\t0\t0\t0000FFFF\n");
        ThisMachine::useRouteFile($this->routeFile);
        $fromRouter = new Request('GET', '/', ['Host' => 'localhost:9507'], [], [], ['remote_addr' => '172.20.0.1'], []);

        self::assertFalse(ThisMachine::sent($fromRouter), 'the host network: 172.20.0.1 is the LAN router');
    }

    #[Test]
    public function a_proxy_on_a_host_forwarding_a_bridge_numbered_router_names_another_machine(): void
    {
        ThisMachine::useContainerMarker($this->routeFile . '.absent');
        $forwarded = new Request('GET', '/', ['Host' => 'localhost:9507', 'X-Forwarded-For' => '172.20.0.1'], [], [], ['remote_addr' => '127.0.0.1'], []);

        self::assertFalse(ThisMachine::sent($forwarded), 'on a host the forwarded hop is a LAN router');

        ThisMachine::useContainerMarker($this->routeFile);
        self::assertTrue(ThisMachine::sent($forwarded), 'in a bridged container the same hop is the bridge');
    }

    #[Test]
    public function no_request_is_this_machine(): void
    {
        self::assertTrue(ThisMachine::sent(null));
    }
}
