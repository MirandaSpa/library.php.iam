<?php

declare(strict_types=1);

namespace Miranda\Iam\Tests;

use Miranda\Iam\Exception\ManifestException;
use Miranda\Iam\Manifest;
use Miranda\Iam\ManifestLoader;
use PHPUnit\Framework\TestCase;

final class ManifestTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function valid(): array
    {
        return [
            'service' => 'store',
            'permissions' => [
                ['id' => 'store:reception:create', 'label' => 'Create reception'],
                ['id' => 'store:reception:view', 'label' => 'View reception', 'scope' => 'global', 'audit' => 'always', 'kind' => 'internal'],
            ],
            'routes' => [
                ['method' => 'POST', 'path' => '/api/v2/storage/reception', 'permission' => 'store:reception:create'],
                ['method' => 'GET', 'path' => '/api/v2/storage/reception/{id}', 'permission' => 'store:reception:view'],
                ['method' => 'GET', 'path' => '/api/v2/storage/health', 'public' => true],
            ],
        ];
    }

    /** @param callable(array):array $mutate */
    private static function mutated(callable $mutate): array
    {
        return $mutate(self::valid());
    }

    public function testValidManifestLoads(): void
    {
        $m = ManifestLoader::fromArray(self::valid());

        $this->assertSame('store', $m->service());
        $this->assertCount(2, $m->permissions());
        $this->assertCount(3, $m->routes());
        $this->assertSame('branch', $m->permissions()[0]->scope());
        $this->assertSame('denied', $m->permissions()[0]->audit());
        $this->assertSame('entry', $m->permissions()[0]->kind());
        $this->assertSame('global', $m->permissions()[1]->scope());
        $this->assertTrue($m->routes()[2]->isPublic());
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function invalidProvider(): array
    {
        $cases = [
            'service missing' => [function (array $d) { unset($d['service']); return $d; }, 'service "" must be'],
            'service uppercase' => [function (array $d) { $d['service'] = 'Store'; return $d; }, 'service "Store" must be'],
            'service too long' => [function (array $d) { $d['service'] = str_repeat('a', 61); return $d; }, 'must be lowercase'],
            'service with space' => [function (array $d) { $d['service'] = 'my store'; return $d; }, 'must be lowercase'],
            'id with 2 segments' => [function (array $d) { $d['permissions'][0]['id'] = 'store:create'; return $d; }, 'must be area:resource:action'],
            'id with 4 segments' => [function (array $d) { $d['permissions'][0]['id'] = 'a:b:c:d'; return $d; }, 'must be area:resource:action'],
            'id uppercase' => [function (array $d) { $d['permissions'][0]['id'] = 'Store:reception:create'; return $d; }, 'must be area:resource:action'],
            'id with empty segment' => [function (array $d) { $d['permissions'][0]['id'] = 'store::create'; return $d; }, 'must be area:resource:action'],
            'id with dot' => [function (array $d) { $d['permissions'][0]['id'] = 'store:recep.tion:create'; return $d; }, 'must be area:resource:action'],
            'id missing' => [function (array $d) { unset($d['permissions'][0]['id']); return $d; }, 'permission "" must be area:resource:action'],
            'id too long' => [function (array $d) { $d['permissions'][0]['id'] = 'a:b:' . str_repeat('c', 120); return $d; }, 'longer than 120'],
            'label missing' => [function (array $d) { unset($d['permissions'][0]['label']); return $d; }, 'needs a label'],
            'label blank' => [function (array $d) { $d['permissions'][0]['label'] = '   '; return $d; }, 'needs a label'],
            'label 256 chars' => [function (array $d) { $d['permissions'][0]['label'] = str_repeat('x', 256); return $d; }, 'label is longer than 255'],
            'description 501 chars' => [function (array $d) { $d['permissions'][0]['description'] = str_repeat('x', 501); return $d; }, 'description is longer than 500'],
            'scope invalid' => [function (array $d) { $d['permissions'][0]['scope'] = 'region'; return $d; }, 'scope must be branch or global'],
            'audit invalid' => [function (array $d) { $d['permissions'][0]['audit'] = 'never'; return $d; }, 'audit must be denied or always'],
            'kind invalid' => [function (array $d) { $d['permissions'][0]['kind'] = 'external'; return $d; }, 'kind must be entry or internal'],
            'label not text' => [function (array $d) { $d['permissions'][0]['label'] = 12; return $d; }, 'label must be text'],
            'permission not a mapping' => [function (array $d) { $d['permissions'][0] = 'nope'; return $d; }, 'permissions[0] must be a mapping'],
            'permissions not a list' => [function (array $d) { $d['permissions'] = 'nope'; return $d; }, 'permissions must be a list'],
            'duplicate permission id' => [function (array $d) { $d['permissions'][1]['id'] = 'store:reception:create'; return $d; }, 'is declared twice'],
            'method lowercase' => [function (array $d) { $d['routes'][0]['method'] = 'post'; return $d; }, 'method must be GET, POST, PUT, PATCH or DELETE'],
            'method unknown' => [function (array $d) { $d['routes'][0]['method'] = 'HEAD'; return $d; }, 'method must be GET'],
            'method missing' => [function (array $d) { unset($d['routes'][0]['method']); return $d; }, 'method must be GET'],
            'path without slash' => [function (array $d) { $d['routes'][0]['path'] = 'api/reception'; return $d; }, 'path must start with /'],
            'path with space' => [function (array $d) { $d['routes'][0]['path'] = '/api/re ception'; return $d; }, 'spaces, ? or #'],
            'path with query' => [function (array $d) { $d['routes'][0]['path'] = '/api/reception?x=1'; return $d; }, 'spaces, ? or #'],
            'path with fragment' => [function (array $d) { $d['routes'][0]['path'] = '/api/reception#top'; return $d; }, 'spaces, ? or #'],
            'path with empty segment' => [function (array $d) { $d['routes'][0]['path'] = '/api//reception'; return $d; }, 'empty segments'],
            'path too long' => [function (array $d) { $d['routes'][0]['path'] = '/' . str_repeat('a', 255); return $d; }, 'path is longer than 255'],
            'param with dot' => [function (array $d) { $d['routes'][1]['path'] = '/api/{user.id}'; return $d; }, 'not a valid {param}'],
            'param unclosed' => [function (array $d) { $d['routes'][1]['path'] = '/api/{id'; return $d; }, 'not a valid {param}'],
            'param empty' => [function (array $d) { $d['routes'][1]['path'] = '/api/{}'; return $d; }, 'not a valid {param}'],
            'param inside a segment' => [function (array $d) { $d['routes'][1]['path'] = '/api/x{id}'; return $d; }, 'not a valid {param}'],
            'route without permission nor public' => [function (array $d) { unset($d['routes'][0]['permission']); return $d; }, 'declare its permission, or mark it public'],
            'route with both' => [function (array $d) { $d['routes'][0]['public'] = true; return $d; }, 'either public or needs a permission'],
            'route permission undeclared' => [function (array $d) { $d['routes'][0]['permission'] = 'store:ghost:view'; return $d; }, 'which the manifest does not declare'],
            'public not a bool' => [function (array $d) { $d['routes'][2]['public'] = 'yes'; return $d; }, 'public must be true or false'],
            'route not a mapping' => [function (array $d) { $d['routes'][0] = 3; return $d; }, 'routes[0] must be a mapping'],
            'duplicate route' => [function (array $d) { $d['routes'][] = $d['routes'][0]; return $d; }, 'is declared twice'],
            'duplicate route with other param name' => [function (array $d) { $d['routes'][] = ['method' => 'GET', 'path' => '/api/v2/storage/reception/{other}', 'permission' => 'store:reception:view']; return $d; }, 'is declared twice'],
            'duplicate route with trailing slash' => [function (array $d) { $d['routes'][] = ['method' => 'POST', 'path' => '/api/v2/storage/reception/', 'permission' => 'store:reception:create']; return $d; }, 'is declared twice'],
        ];

        $out = [];
        foreach ($cases as $name => [$mutate, $needle]) {
            $out[$name] = [self::mutated($mutate), $needle];
        }

        return $out;
    }

    /**
     * @dataProvider invalidProvider
     *
     * @param array<string,mixed> $data
     */
    public function testInvalidManifestIsRejected(array $data, string $expected): void
    {
        try {
            ManifestLoader::fromArray($data);
            $this->fail('the manifest should be invalid');
        } catch (ManifestException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
            $this->assertStringContainsString($expected, implode("\n", $e->problems()));
        }
    }

    public function testAllProblemsAreReportedAtOnce(): void
    {
        $data = self::valid();
        $data['service'] = 'BAD';
        $data['permissions'][0]['id'] = 'nope';
        $data['permissions'][1]['label'] = '';
        $data['routes'][0]['method'] = 'get';
        $data['routes'][1]['path'] = 'no-slash';

        try {
            ManifestLoader::fromArray($data);
            $this->fail('the manifest should be invalid');
        } catch (ManifestException $e) {
            $this->assertGreaterThanOrEqual(5, count($e->problems()));
            $all = $e->getMessage();
            $this->assertStringContainsString('service "BAD"', $all);
            $this->assertStringContainsString('permission "nope"', $all);
            $this->assertStringContainsString('needs a label', $all);
            $this->assertStringContainsString('method must be', $all);
            $this->assertStringContainsString('path must start with /', $all);
            $this->assertStringStartsWith('invalid manifest: ', $all);
        }
    }

    public function testLimitsAreInclusive(): void
    {
        $data = self::valid();
        $data['service'] = str_repeat('a', 60);
        $data['permissions'][0]['label'] = str_repeat('é', 255);
        $data['permissions'][0]['description'] = str_repeat('é', 500);

        $m = ManifestLoader::fromArray($data);

        $this->assertSame(60, strlen($m->service()));
    }

    public function testEmptyManifestNeedsAServiceOnly(): void
    {
        $m = ManifestLoader::fromArray(['service' => 'tiny']);

        $this->assertSame(['permissions' => []], $m->toRegistrationBody());
    }

    public function testRegistrationBodyHasNoServiceAndUsesDefaults(): void
    {
        $body = ManifestLoader::fromArray(self::valid())->toRegistrationBody();

        $this->assertArrayNotHasKey('service', $body);
        $this->assertSame(
            ['id' => 'store:reception:create', 'label' => 'Create reception', 'scope' => 'branch', 'audit' => 'denied', 'kind' => 'entry'],
            $body['permissions'][0]
        );
        $this->assertSame(
            ['id' => 'store:reception:view', 'label' => 'View reception', 'scope' => 'global', 'audit' => 'always', 'kind' => 'internal'],
            $body['permissions'][1]
        );
        $this->assertSame(['method' => 'POST', 'path' => '/api/v2/storage/reception', 'permission' => 'store:reception:create'], $body['routes'][0]);
        $this->assertSame(['method' => 'GET', 'path' => '/api/v2/storage/health', 'public' => true], $body['routes'][2]);
    }

    public function testDescriptionIsSentOnlyWhenPresent(): void
    {
        $data = self::valid();
        $data['permissions'][0]['description'] = 'Lets you create a reception.';

        $body = ManifestLoader::fromArray($data)->toRegistrationBody();

        $this->assertSame('Lets you create a reception.', $body['permissions'][0]['description']);
        $this->assertArrayNotHasKey('description', $body['permissions'][1]);
    }

    public function testHandBuiltManifestIsValidatedToo(): void
    {
        $m = new Manifest('Bad Name', []);

        $this->expectException(ManifestException::class);
        $m->validate();
    }

    public function testLegacySliceFixtureLoads(): void
    {
        $m = ManifestLoader::fromFile(__DIR__ . '/fixtures/legacy-slice.yaml');

        $this->assertSame('legacy', $m->service());
        $this->assertCount(7, $m->permissions());
        $this->assertCount(8, $m->routes());
        $this->assertSame('always', $m->permissions()[1]->audit());
        $this->assertSame('global', $m->permissions()[6]->scope());
        $this->assertTrue($m->routes()[7]->isPublic());
        $body = $m->toRegistrationBody();
        $this->assertCount(7, $body['permissions']);
        $this->assertCount(8, $body['routes']);
        $this->assertStringContainsString('Ver un trabajo de impresión', (string) json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    public function testFromFileErrors(): void
    {
        $dir = sys_get_temp_dir() . '/iam-manifest-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $cases = [
                'missing.yaml' => null,
                'broken.yaml' => "service: [unclosed\n  - x: : :",
                'scalar.yaml' => 'just a string',
                'empty.yaml' => '',
                'invalid.yaml' => "service: Bad\n",
            ];
            $expect = [
                'missing.yaml' => 'cannot read the manifest file',
                'broken.yaml' => 'not valid YAML',
                'scalar.yaml' => 'must be a YAML mapping',
                'empty.yaml' => 'must be a YAML mapping',
                'invalid.yaml' => 'service "Bad"',
            ];
            foreach ($cases as $file => $content) {
                if ($content !== null) {
                    file_put_contents($dir . '/' . $file, $content);
                }
                try {
                    ManifestLoader::fromFile($dir . '/' . $file);
                    $this->fail($file . ' should fail');
                } catch (ManifestException $e) {
                    $this->assertStringContainsString($expect[$file], $e->getMessage(), $file);
                }
            }
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }
}
