<?php

declare(strict_types=1);

namespace Miranda\Iam\Tests;

use Miranda\Iam\Http\HttpResponse;
use Miranda\Iam\IamBootstrap;
use Miranda\Iam\ManifestRegistrar;
use Miranda\Iam\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class IamBootstrapTest extends TestCase
{
    private const IAM = 'https://iam.example.test/api/v2/iam';
    private const MANIFEST = __DIR__ . '/fixtures/legacy-slice.yaml';

    private function bootstrap(FakeTransport $t, int &$sleeps): IamBootstrap
    {
        return new IamBootstrap(new ManifestRegistrar($t), 3, 1, static function () use (&$sleeps): void {
            ++$sleeps;
        });
    }

    public function testOk(): void
    {
        $sleeps = 0;
        $t = new FakeTransport([FakeTransport::json(200, ['permissions' => 7, 'routes' => 8])]);

        $r = $this->bootstrap($t, $sleeps)->run([self::MANIFEST, self::IAM]);

        $this->assertSame(0, $r->exitCode());
        $this->assertSame('manifest of legacy registered in iam (7 permissions, 8 routes)', $r->message());
    }

    public function testRetriesThenOk(): void
    {
        $sleeps = 0;
        $t = new FakeTransport([new HttpResponse(503), FakeTransport::json(200, ['permissions' => 7, 'routes' => 8])]);

        $r = $this->bootstrap($t, $sleeps)->register(self::MANIFEST, self::IAM);

        $this->assertSame(0, $r->exitCode());
        $this->assertSame(1, $sleeps);
    }

    public function testRejectedIsExit1AndNotRetried(): void
    {
        $sleeps = 0;
        $t = new FakeTransport([FakeTransport::json(409, ['detail' => '"a:b:c" is declared by "x"'])]);

        $r = $this->bootstrap($t, $sleeps)->register(self::MANIFEST, self::IAM);

        $this->assertSame(1, $r->exitCode());
        $this->assertStringContainsString('"a:b:c" is declared by "x"', $r->message());
        $this->assertSame(0, $sleeps);
    }

    public function testUnavailableAfterRetriesIsExit2(): void
    {
        $sleeps = 0;
        $t = new FakeTransport([FakeTransport::down(), FakeTransport::down(), new HttpResponse(500)]);

        $r = $this->bootstrap($t, $sleeps)->register(self::MANIFEST, self::IAM);

        $this->assertSame(2, $r->exitCode());
        $this->assertStringContainsString('after 3 attempt(s)', $r->message());
        $this->assertSame(2, $sleeps);
    }

    public function testInvalidOrMissingManifestIsExit1WithoutCallingIam(): void
    {
        $sleeps = 0;
        $t = new FakeTransport();

        $r = $this->bootstrap($t, $sleeps)->register(__DIR__ . '/fixtures/does-not-exist.yaml', self::IAM);

        $this->assertSame(1, $r->exitCode());
        $this->assertStringContainsString('cannot read the manifest file', $r->message());
        $this->assertCount(0, $t->requests);
    }

    public function testBadIamUrlIsExit1(): void
    {
        $sleeps = 0;

        $r = $this->bootstrap(new FakeTransport(), $sleeps)->register(self::MANIFEST, 'iam.example.test');

        $this->assertSame(1, $r->exitCode());
        $this->assertStringContainsString('absolute http(s) URL', $r->message());
    }

    /**
     * @return array<string,array{0:array<mixed>}>
     */
    public static function badArgs(): array
    {
        return [
            'none' => [[]],
            'one' => [['only-one']],
            'three' => [['a', 'b', 'c']],
            'empty path' => [['', self::IAM]],
            'empty url' => [[self::MANIFEST, '']],
        ];
    }

    /**
     * @dataProvider badArgs
     *
     * @param array<mixed> $args
     */
    public function testUsageErrorIsExit1(array $args): void
    {
        $sleeps = 0;

        $r = $this->bootstrap(new FakeTransport(), $sleeps)->run($args);

        $this->assertSame(1, $r->exitCode());
        $this->assertSame(IamBootstrap::USAGE, $r->message());
    }

    public function testDefaultsConstruct(): void
    {
        $this->assertInstanceOf(IamBootstrap::class, new IamBootstrap());
    }
}
