<?php

declare(strict_types=1);

namespace Miranda\Iam\Tests;

use Miranda\Iam\Credentials;
use Miranda\Iam\Exception\CredentialsException;
use PHPUnit\Framework\TestCase;

final class CredentialsTest extends TestCase
{
    private const SECRET = 'S3cr3t-Cr3d-9f8e7d6c5b4a';

    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/iam-creds-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    private function file(string $name, string $content, int $mode): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);
        chmod($path, $mode);

        return $path;
    }

    private function assertNoSecret(string $text, string $where): void
    {
        $this->assertStringNotContainsString(self::SECRET, $text, $where . ' leaks the secret');
    }

    public function testFromEnv(): void
    {
        $c = Credentials::fromEnv([
            'IAM_SERVICE_USERNAME' => 'svc-legacy',
            'IAM_SERVICE_CREDENTIAL' => self::SECRET,
            'IAM_CLIENT_ID' => 'client-123',
            'IAM_TOKEN_URL' => 'https://idp.example.test/token',
        ]);

        $this->assertSame('svc-legacy', $c->username());
        $this->assertSame(self::SECRET, $c->credential());
        $this->assertSame('client-123', $c->clientId());
        $this->assertSame('https://idp.example.test/token', $c->tokenUrl());
    }

    public function testFromEnvNamesTheMissingVariablesOnly(): void
    {
        try {
            Credentials::fromEnv(['IAM_SERVICE_CREDENTIAL' => '', 'IAM_CLIENT_ID' => 'x']);
            $this->fail('should fail');
        } catch (CredentialsException $e) {
            $this->assertSame('missing environment variable(s): IAM_SERVICE_USERNAME, IAM_SERVICE_CREDENTIAL', $e->getMessage());
        }
    }

    public function testFromEnvIgnoresNonStringValues(): void
    {
        $this->expectException(CredentialsException::class);
        Credentials::fromEnv(['IAM_SERVICE_USERNAME' => ['x'], 'IAM_SERVICE_CREDENTIAL' => false]);
    }

    public function testFromFile(): void
    {
        $path = $this->file('ok.json', (string) json_encode(['username' => 'svc-legacy', 'credential' => self::SECRET]), 0600);

        $c = Credentials::fromFile($path, 'client-1', 'https://idp.example.test/token');

        $this->assertSame('svc-legacy', $c->username());
        $this->assertSame(self::SECRET, $c->credential());
        $this->assertSame('client-1', $c->clientId());
    }

    public function testFromFileMayCarryTheEndpoint(): void
    {
        $path = $this->file('ep.json', (string) json_encode([
            'username' => 'svc-a', 'credential' => self::SECRET, 'client_id' => 'cid', 'token_url' => 'https://idp.example.test/t',
        ]), 0400);

        $c = Credentials::fromFile($path);

        $this->assertSame('cid', $c->clientId());
        $this->assertSame('https://idp.example.test/t', $c->tokenUrl());
        $with = $c->withEndpoint('other', 'https://idp2.example.test/t');
        $this->assertSame('other', $with->clientId());
        $this->assertSame(self::SECRET, $with->credential());
    }

    /**
     * @return array<string,array{0:int}>
     */
    public static function unsafeModes(): array
    {
        return ['group readable 640' => [0640], 'world readable 644' => [0644], 'group writable 620' => [0620], 'world 604' => [0604]];
    }

    /**
     * @dataProvider unsafeModes
     */
    public function testFileReadableByOthersIsRefused(int $mode): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permissions do not exist on Windows (the check is skipped there by design)');
        }
        $path = $this->file('open.json', (string) json_encode(['username' => 'svc-a', 'credential' => self::SECRET]), $mode);

        try {
            Credentials::fromFile($path);
            $this->fail('should be refused');
        } catch (CredentialsException $e) {
            $this->assertStringContainsString('readable by group or others', $e->getMessage());
            $this->assertStringContainsString('chmod 600', $e->getMessage());
            $this->assertStringContainsString(sprintf('%04o', $mode), $e->getMessage());
            $this->assertNoSecret($e->getMessage(), 'exception');
        }
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function badContents(): array
    {
        return [
            'not json' => ['not json ' . self::SECRET, 'not valid JSON'],
            'json scalar' => ['"' . self::SECRET . '"', 'not valid JSON'],
            'no username' => [(string) json_encode(['credential' => self::SECRET]), 'must have "username" and "credential"'],
            'no credential' => [(string) json_encode(['username' => 'svc-a']), 'must have "username" and "credential"'],
            'credential not text' => [(string) json_encode(['username' => 'svc-a', 'credential' => 12345]), 'must have "username" and "credential"'],
        ];
    }

    /**
     * @dataProvider badContents
     */
    public function testBadFileContents(string $content, string $expected): void
    {
        $path = $this->file('bad.json', $content, 0600);

        try {
            Credentials::fromFile($path);
            $this->fail('should fail');
        } catch (CredentialsException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
            $this->assertNoSecret($e->getMessage(), 'exception');
        }
    }

    public function testMissingFileOrDirectory(): void
    {
        foreach ([$this->dir . '/nope.json', $this->dir] as $path) {
            try {
                Credentials::fromFile($path);
                $this->fail('should fail');
            } catch (CredentialsException $e) {
                $this->assertStringContainsString('cannot read the credentials file', $e->getMessage());
            }
        }
    }

    public function testConstructorRefusesEmptyValues(): void
    {
        $this->expectException(CredentialsException::class);
        new Credentials('svc', '');
    }

    public function testTheSecretNeverPrints(): void
    {
        $c = new Credentials('svc-legacy', self::SECRET, 'client-1', 'https://idp.example.test/token');

        $this->assertNoSecret((string) $c, '__toString');
        $this->assertNoSecret(var_export($c, true), 'var_export');
        $this->assertNoSecret((string) json_encode($c), 'json_encode');
        $this->assertNoSecret(print_r($c, true), 'print_r');
        $this->assertNoSecret(serialize_attempt($c), 'serialize message');
        ob_start();
        var_dump($c);
        $dump = (string) ob_get_clean();
        $this->assertNoSecret($dump, 'var_dump');
        $this->assertNoSecret(json_encode([$c, 'nested' => ['c' => $c]]) ?: '', 'nested json');
        $this->assertNoSecret(sprintf('%s', $c), 'sprintf');
        $this->assertNoSecret(print_r(['creds' => $c], true), 'print_r nested');
        $this->assertNoSecret((string) json_encode((array) $c), '(array) cast');

        $this->assertStringContainsString('[REDACTED]', (string) $c);
        $this->assertSame('[REDACTED]', json_decode((string) json_encode($c), true)['credential']);
        $this->assertSame('svc-legacy', json_decode((string) json_encode($c), true)['username']);
    }

    public function testNoSecretInExceptionTrace(): void
    {
        try {
            $c = new Credentials('svc', self::SECRET);
            (static function (Credentials $c): void {
                throw new \RuntimeException('boom');
            })($c);
        } catch (\RuntimeException $e) {
            $this->assertNoSecret($e->getMessage(), 'message');
            $this->assertNoSecret(var_export(array_slice($e->getTrace(), 0, 1), true), 'var_export of the frame that received the credentials');
        }
    }

    public function testCannotBeUnserialized(): void
    {
        $this->expectException(\LogicException::class);
        (new Credentials('svc', self::SECRET))->__unserialize([]);
    }
}

/** Serializing is refused; the message of the refusal must not carry the secret. */
function serialize_attempt(Credentials $c): string
{
    try {
        return serialize($c);
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}
