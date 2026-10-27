<?php

declare(strict_types=1);

namespace Miranda\Iam\Tests;

use InvalidArgumentException;
use Miranda\Iam\Exception\ManifestException;
use Miranda\Iam\Exception\RegistrationRejectedException;
use Miranda\Iam\Exception\RegistrationUnavailableException;
use Miranda\Iam\Http\HttpResponse;
use Miranda\Iam\ManifestLoader;
use Miranda\Iam\ManifestRegistrar;
use Miranda\Iam\Manifest;
use Miranda\Iam\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ManifestRegistrarTest extends TestCase
{
    private const IAM = 'https://iam.example.test/api/v2/iam';

    private function manifest(): Manifest
    {
        return ManifestLoader::fromFile(__DIR__ . '/fixtures/legacy-slice.yaml');
    }

    public function testRegisterSendsExactRequestAndReturnsCounts(): void
    {
        $t = new FakeTransport([FakeTransport::json(200, ['permissions' => 7, 'routes' => 8])]);
        $m = $this->manifest();

        $result = (new ManifestRegistrar($t))->register($m, self::IAM . '/');

        $this->assertSame(7, $result->permissions());
        $this->assertSame(8, $result->routes());
        $this->assertCount(1, $t->requests);
        $req = $t->last();
        $this->assertSame('PUT', $req->method());
        $this->assertSame('https://iam.example.test/api/v2/iam/internal/manifests/legacy', $req->url());
        $this->assertSame('application/json', $req->header('content-type'));
        $this->assertSame(5000, $req->timeoutMs());
        $this->assertNull($req->header('Authorization'));
        $this->assertSame(json_encode($m->toRegistrationBody(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $req->body());
        $decoded = json_decode((string) $req->body(), true);
        $this->assertArrayNotHasKey('service', $decoded);
        $this->assertCount(7, $decoded['permissions']);
    }

    public function testTimeoutIsConfigurable(): void
    {
        $t = new FakeTransport([FakeTransport::json(200, ['permissions' => 1, 'routes' => 0])]);

        (new ManifestRegistrar($t, 1.5))->register($this->manifest(), self::IAM);

        $this->assertSame(1500, $t->last()->timeoutMs());
    }

    /**
     * @return array<string,array{0:int,1:array<string,mixed>,2:string}>
     */
    public static function rejectionProvider(): array
    {
        return [
            '400 with detail' => [400, ['title' => 'Bad Request', 'detail' => 'route GET /x uses "a:b:c", which the manifest does not declare'], 'which the manifest does not declare'],
            '409 names permission and services' => [409, ['detail' => '"cash:register:close" is declared by "cash-register" with scope branch; "legacy" declares it with scope global'], '"cash:register:close" is declared by "cash-register"'],
            '422 with field errors' => [422, ['detail' => 'validation failed', 'errors' => [['message' => 'expected length <= 255', 'location' => 'body.permissions.0.label']]], 'body.permissions.0.label: expected length <= 255'],
        ];
    }

    /**
     * @dataProvider rejectionProvider
     *
     * @param array<string,mixed> $answer
     */
    public function testRejectionCarriesOnlyWhatIamSays(int $status, array $answer, string $expected): void
    {
        $t = new FakeTransport([FakeTransport::json($status, $answer)]);

        try {
            (new ManifestRegistrar($t))->register($this->manifest(), self::IAM);
            $this->fail('should be rejected');
        } catch (RegistrationRejectedException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
            $this->assertStringContainsString('HTTP ' . $status, $e->getMessage());
        }
    }

    public function testRejectionWithNonJsonBodyDoesNotEchoIt(): void
    {
        $t = new FakeTransport([new HttpResponse(409, '<html>secret-looking-page</html>')]);

        try {
            (new ManifestRegistrar($t))->register($this->manifest(), self::IAM);
            $this->fail('should be rejected');
        } catch (RegistrationRejectedException $e) {
            $this->assertSame('iam rejected the manifest (HTTP 409)', $e->getMessage());
        }
    }

    public function testRejectionMessageIsCleanAndBounded(): void
    {
        $t = new FakeTransport([FakeTransport::json(400, ['detail' => "line1\nline2" . str_repeat('x', 3000)])]);

        try {
            (new ManifestRegistrar($t))->register($this->manifest(), self::IAM);
            $this->fail('should be rejected');
        } catch (RegistrationRejectedException $e) {
            $this->assertStringNotContainsString("\n", $e->getMessage());
            $this->assertLessThanOrEqual(1003, strlen($e->getMessage()));
        }
    }

    /**
     * @return array<string,array{0:\Throwable|HttpResponse}>
     */
    public static function unavailableProvider(): array
    {
        return [
            '500' => [new HttpResponse(500, '{"detail":"boom"}')],
            '502' => [new HttpResponse(502)],
            '503' => [new HttpResponse(503)],
            '404 (wrong URL or proxy not ready)' => [new HttpResponse(404)],
            'network error' => [FakeTransport::down()],
            'timeout' => [FakeTransport::down('timeout')],
            '200 with garbage' => [new HttpResponse(200, 'not json')],
            '200 without counts' => [new HttpResponse(200, '{}')],
        ];
    }

    /**
     * @dataProvider unavailableProvider
     *
     * @param \Throwable|HttpResponse $answer
     */
    public function testUnavailableCases($answer): void
    {
        $t = new FakeTransport([$answer]);

        $this->expectException(RegistrationUnavailableException::class);
        (new ManifestRegistrar($t))->register($this->manifest(), self::IAM);
    }

    public function testUnavailableMessageDoesNotEchoTheBody(): void
    {
        $t = new FakeTransport([new HttpResponse(500, 'stack trace with internals')]);

        try {
            (new ManifestRegistrar($t))->register($this->manifest(), self::IAM);
            $this->fail('should be unavailable');
        } catch (RegistrationUnavailableException $e) {
            $this->assertSame('iam answered HTTP 500', $e->getMessage());
        }
    }

    public function testInvalidManifestNeverReachesIam(): void
    {
        $t = new FakeTransport();

        try {
            (new ManifestRegistrar($t))->register(new Manifest('BAD', []), self::IAM);
            $this->fail('should be invalid');
        } catch (ManifestException $e) {
            $this->assertCount(0, $t->requests);
        }
    }

    public function testBadIamUrlIsRefused(): void
    {
        foreach (['iam.example.test', 'ftp://iam.example.test', 'https://user:pw@iam.example.test', 'https://iam.example.test/x?y=1', ''] as $url) {
            try {
                (new ManifestRegistrar(new FakeTransport()))->register($this->manifest(), $url);
                $this->fail('should refuse ' . $url);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('absolute http(s) URL', $e->getMessage());
            }
        }
    }

    public function testRetriesThenSucceeds(): void
    {
        $t = new FakeTransport([
            FakeTransport::down(),
            new HttpResponse(503),
            FakeTransport::json(200, ['permissions' => 7, 'routes' => 8]),
        ]);
        $slept = [];
        $retries = [];

        $result = (new ManifestRegistrar($t))->registerWithRetry(
            $this->manifest(),
            self::IAM,
            5,
            3,
            static function (int $s) use (&$slept): void {
                $slept[] = $s;
            },
            static function (int $attempt, RegistrationUnavailableException $e, int $delay) use (&$retries): void {
                $retries[] = [$attempt, $delay];
            }
        );

        $this->assertSame(7, $result->permissions());
        $this->assertCount(3, $t->requests);
        $this->assertSame([3, 3], $slept);
        $this->assertSame([[1, 3], [2, 3]], $retries);
    }

    public function testRetriesExhausted(): void
    {
        $t = new FakeTransport([new HttpResponse(500), new HttpResponse(500), new HttpResponse(502)]);
        $slept = 0;

        try {
            (new ManifestRegistrar($t))->registerWithRetry($this->manifest(), self::IAM, 3, 1, static function () use (&$slept): void {
                ++$slept;
            });
            $this->fail('should be unavailable');
        } catch (RegistrationUnavailableException $e) {
            $this->assertStringContainsString('after 3 attempt(s)', $e->getMessage());
            $this->assertStringContainsString('HTTP 502', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
        }
        $this->assertCount(3, $t->requests);
        $this->assertSame(2, $slept, 'no wait after the last attempt');
    }

    public function testRejectionStopsAtOnce(): void
    {
        $t = new FakeTransport([FakeTransport::json(409, ['detail' => 'taken']), FakeTransport::json(200, ['permissions' => 1, 'routes' => 1])]);
        $slept = 0;

        try {
            (new ManifestRegistrar($t))->registerWithRetry($this->manifest(), self::IAM, 5, 1, static function () use (&$slept): void {
                ++$slept;
            });
            $this->fail('should be rejected');
        } catch (RegistrationRejectedException $e) {
            $this->assertStringContainsString('taken', $e->getMessage());
        }
        $this->assertCount(1, $t->requests);
        $this->assertSame(0, $slept);
    }

    public function testInvalidManifestIsNotRetried(): void
    {
        $t = new FakeTransport();

        $this->expectException(ManifestException::class);
        try {
            (new ManifestRegistrar($t))->registerWithRetry(new Manifest('BAD', []), self::IAM, 5, 1, static function (): void {
            });
        } finally {
            $this->assertCount(0, $t->requests);
        }
    }

    public function testRetryArgumentsAreChecked(): void
    {
        $r = new ManifestRegistrar(new FakeTransport());
        foreach ([[0, 1], [1, -1]] as [$attempts, $delay]) {
            try {
                $r->registerWithRetry($this->manifest(), self::IAM, $attempts, $delay, static function (): void {
                });
                $this->fail('should refuse');
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDefaultSleepIsUsedWhenNotInjected(): void
    {
        $t = new FakeTransport([FakeTransport::down(), FakeTransport::json(200, ['permissions' => 1, 'routes' => 0])]);

        $result = (new ManifestRegistrar($t))->registerWithRetry($this->manifest(), self::IAM, 2, 0);

        $this->assertSame(1, $result->permissions());
    }
}
