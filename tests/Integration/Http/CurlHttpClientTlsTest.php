<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Client\CurlHttpClient;
use Semitexa\Core\Http\Client\HttpCallOptions;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Runtime;

/**
 * Inside the server curl is Swoole's hook, which checked no certificate: a
 * self-signed or wrong-host one was taken, so anyone on the path read the
 * API keys a call carried. Here a local HTTPS server shows three
 * certificates to the hooked client: one from no CA it trusts, one from a CA
 * it trusts for another host, and one it must accept.
 */
final class CurlHttpClientTlsTest extends TestCase
{
    private string $dir;

    /** The process's own CA bundle setting, put back for the tests that run after this one. */
    private string|false $sslCertFile;

    protected function setUp(): void
    {
        $this->sslCertFile = getenv('SSL_CERT_FILE');
        $this->dir = sys_get_temp_dir() . '/semitexa-tls-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        putenv($this->sslCertFile === false ? 'SSL_CERT_FILE' : 'SSL_CERT_FILE=' . $this->sslCertFile);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[Test]
    public function the_hooked_client_checks_the_certificate_and_the_host(): void
    {
        [$caCert, $caKey] = $this->certificate('Semitexa Test CA', null, null, true);
        file_put_contents($this->dir . '/ca.pem', $caCert);
        $this->writePair('untrusted', ...$this->certificate('localhost', null, null));
        $this->writePair('other-host', ...$this->certificate('other.test', $caCert, $caKey));
        $this->writePair('good', ...$this->certificate('localhost', $caCert, $caKey));
        // Review: the host-name check broke every HTTPS call to an IP literal
        // (X509_check_host never matches an IP); its chain is still checked.
        $this->writePair('ip', ...$this->certificate('127.0.0.1', $caCert, $caKey, false, 'IP:127.0.0.1'));
        $this->writePair('ip-untrusted', ...$this->certificate('127.0.0.1', null, null, false, 'IP:127.0.0.1'));
        // The test CA is the only one trusted: OpenSSL's default verify file.
        putenv('SSL_CERT_FILE=' . $this->dir . '/ca.pem');

        $outcomes = [];
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        try {
            Coroutine\run(function () use (&$outcomes): void {
                foreach (['untrusted' => 'localhost', 'other-host' => 'localhost', 'good' => 'localhost', 'ip' => '127.0.0.1', 'ip-untrusted' => '127.0.0.1'] as $name => $host) {
                    $server = new Server('127.0.0.1', 0, true);
                    $server->set([
                        'ssl_cert_file' => $this->dir . "/{$name}.crt",
                        'ssl_key_file' => $this->dir . "/{$name}.key",
                    ]);
                    $server->handle('/', static function (Request $request, Response $response): void {
                        $response->end('ok');
                    });
                    Coroutine::create(static fn () => $server->start());
                    try {
                        $response = (new CurlHttpClient())->get('https://' . $host . ':' . $server->port . '/', [], new HttpCallOptions(timeout: 5));
                        $outcomes[$name] = 'accepted ' . $response->status;
                    } catch (\Throwable $e) {
                        $outcomes[$name] = 'refused';
                    } finally {
                        $server->shutdown();
                    }
                }
            });
        } finally {
            Runtime::enableCoroutine(0);
            Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
        }

        self::assertSame(
            ['untrusted' => 'refused', 'other-host' => 'refused', 'good' => 'accepted 200', 'ip' => 'accepted 200', 'ip-untrusted' => 'refused'],
            $outcomes,
        );
    }

    private function writePair(string $name, string $cert, string $key): void
    {
        file_put_contents($this->dir . "/{$name}.crt", $cert);
        file_put_contents($this->dir . "/{$name}.key", $key);
    }

    /** @return array{string, string} PEM certificate and key */
    private function certificate(string $commonName, ?string $caCert, ?string $caKey, bool $isCa = false, ?string $san = null): array
    {
        $config = $this->dir . '/openssl-' . bin2hex(random_bytes(4)) . '.cnf';
        file_put_contents($config, implode("\n", [
            '[req]', 'distinguished_name = dn', '[dn]',
            '[ext]', $isCa ? 'basicConstraints = critical,CA:TRUE' : 'basicConstraints = CA:FALSE',
            $isCa ? 'keyUsage = critical,keyCertSign,cRLSign' : 'subjectAltName = ' . ($san ?? 'DNS:' . $commonName),
            '',
        ]));
        $options = ['config' => $config, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => $commonName], $key, $options);
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, $caCert, $caKey ?? $key, 1, $options, random_int(1, PHP_INT_MAX));
        self::assertNotFalse($cert);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, null, ['config' => $config]);

        return [$certPem, $keyPem];
    }
}
