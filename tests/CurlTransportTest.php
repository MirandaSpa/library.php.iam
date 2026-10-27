<?php

declare(strict_types=1);

namespace Miranda\Iam\Tests;

use Miranda\Iam\Http\CurlTransport;
use Miranda\Iam\Http\HttpRequest;
use Miranda\Iam\Http\TransportException;
use PHPUnit\Framework\TestCase;

/**
 * Talks to PHP's built-in server on 127.0.0.1 (loopback, no network needed).
 */
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $process;
    /** @var int */
    private static $port = 0;

    public static function setUpBeforeClass(): void
    {
        for ($try = 0; $try < 5 && self::$process === null; ++$try) {
            $probe = stream_socket_server('tcp://127.0.0.1:0');
            if ($probe === false) {
                continue;
            }
            $port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
            fclose($probe);

            $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/Support/router.php'];
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($proc)) {
                continue;
            }
            for ($i = 0; $i < 60; ++$i) {
                $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($c !== false) {
                    fclose($c);
                    self::$process = $proc;
                    self::$port = $port;
                    break;
                }
                usleep(50000);
            }
            if (self::$process === null) {
                proc_terminate($proc);
                proc_close($proc);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    private function url(string $path): string
    {
        if (self::$process === null) {
            $this->fail('the local test server (php -S on 127.0.0.1) could not start');
        }

        return 'http://127.0.0.1:' . self::$port . $path;
    }

    public function testRoundTrip(): void
    {
        $res = (new CurlTransport())->send(new HttpRequest('PUT', $this->url('/echo?a=1'), ['X-Custom' => 'hello', 'Content-Type' => 'application/json'], '{"x":1}', 3000));

        $this->assertSame(201, $res->status());
        $this->assertSame('yes', $res->header('x-test'));
        $data = json_decode($res->body(), true);
        $this->assertSame('PUT', $data['method']);
        $this->assertSame('{"x":1}', $data['body']);
        $this->assertSame('hello', $data['custom']);
        $this->assertSame('a=1', $data['query']);
    }

    public function testNon2xxIsAnAnswerNotAnError(): void
    {
        $res = (new CurlTransport())->send(new HttpRequest('GET', $this->url('/nothing'), [], null, 3000));

        $this->assertSame(404, $res->status());
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $res = (new CurlTransport())->send(new HttpRequest('GET', $this->url('/redirect'), [], null, 3000));

        $this->assertSame(302, $res->status());
        $this->assertSame('/echo', $res->header('Location'));
    }

    public function testTimeoutIsATransportError(): void
    {
        $this->expectException(TransportException::class);
        (new CurlTransport())->send(new HttpRequest('GET', $this->url('/slow'), [], null, 200));
    }

    public function testHugeAnswerIsRefused(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('too big');
        (new CurlTransport())->send(new HttpRequest('GET', $this->url('/huge'), [], null, 5000));
    }

    public function testConnectionRefused(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe); // the port is now closed

        $this->expectException(TransportException::class);
        (new CurlTransport())->send(new HttpRequest('GET', 'http://' . $name . '/', [], null, 1000));
    }

    public function testOnlyHttpIsSpoken(): void
    {
        $this->expectException(TransportException::class);
        (new CurlTransport())->send(new HttpRequest('GET', 'file://' . __FILE__, [], null, 1000));
    }

    public function testRequestRefusesHeaderInjectionAndZeroTimeout(): void
    {
        foreach ([
            static function (): HttpRequest {
                return new HttpRequest('GET', 'http://x.test', ["X-A" => "a\r\nX-B: b"]);
            },
            static function (): HttpRequest {
                return new HttpRequest('GET', 'http://x.test', [], null, 0);
            },
        ] as $make) {
            try {
                $make();
                $this->fail('should refuse');
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRequestAndResponseAccessors(): void
    {
        $req = new HttpRequest('post', 'http://x.test', ['Content-Type' => 'a/b'], 'body', 10);
        $this->assertSame('POST', $req->method());
        $this->assertSame('a/b', $req->header('content-type'));
        $this->assertNull($req->header('missing'));

        $res = new \Miranda\Iam\Http\HttpResponse(200, 'ok', ['X-Foo' => 'bar']);
        $this->assertSame('bar', $res->header('x-FOO'));
        $this->assertNull($res->header('nope'));
        $this->assertSame('ok', $res->body());
    }
}
