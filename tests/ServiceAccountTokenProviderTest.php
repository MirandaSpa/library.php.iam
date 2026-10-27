<?php

declare(strict_types=1);

namespace Miranda\Iam\Tests;

use InvalidArgumentException;
use Miranda\Iam\Credentials;
use Miranda\Iam\Exception\TokenUnavailableException;
use Miranda\Iam\Http\HttpResponse;
use Miranda\Iam\ServiceAccountTokenProvider;
use Miranda\Iam\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ServiceAccountTokenProviderTest extends TestCase
{
    private const SECRET = 'S3cr3t-Cr3d-9f8e7d6c5b4a';
    private const TOKEN_URL = 'https://idp.example.test/application/o/token/';

    /** @var int */
    private $now = 1000000;

    private function creds(): Credentials
    {
        return new Credentials('svc-legacy', self::SECRET, 'client-1', self::TOKEN_URL);
    }

    private function provider(FakeTransport $t, string $scope = 'openid profile'): ServiceAccountTokenProvider
    {
        return new ServiceAccountTokenProvider($this->creds(), $t, $scope, function (): int {
            return $this->now;
        });
    }

    private static function ok(string $token, ?int $expiresIn = 3600): HttpResponse
    {
        $body = ['access_token' => $token, 'token_type' => 'Bearer'];
        if ($expiresIn !== null) {
            $body['expires_in'] = $expiresIn;
        }

        return FakeTransport::json(200, $body);
    }

    public function testRequestIsExactAndDefaultScopeHasProfile(): void
    {
        $t = new FakeTransport([self::ok('tok-1')]);

        $this->assertSame('tok-1', $this->provider($t)->token());

        $req = $t->last();
        $this->assertSame('POST', $req->method());
        $this->assertSame(self::TOKEN_URL, $req->url());
        $this->assertSame('application/x-www-form-urlencoded', $req->header('Content-Type'));
        parse_str((string) $req->body(), $form);
        $this->assertSame([
            'grant_type' => 'client_credentials',
            'client_id' => 'client-1',
            'username' => 'svc-legacy',
            'password' => self::SECRET,
            'scope' => 'openid profile',
        ], $form);
        $this->assertStringContainsString('scope=openid%20profile', (string) $req->body());
        $this->assertNull($req->header('Authorization'));
    }

    public function testScopeCanBeChanged(): void
    {
        $t = new FakeTransport([self::ok('tok')]);

        $this->provider($t, 'openid profile email')->token();

        parse_str((string) $t->last()->body(), $form);
        $this->assertSame('openid profile email', $form['scope']);
    }

    public function testTokenIsCachedUntilItIsAboutToExpire(): void
    {
        $t = new FakeTransport([self::ok('tok-1', 3600), self::ok('tok-2', 3600)]);
        $p = $this->provider($t);

        $this->assertSame('tok-1', $p->token());
        $this->now += 3000;
        $this->assertSame('tok-1', $p->token());
        $this->now += 539; // 3539 s: 61 s left
        $this->assertSame('tok-1', $p->token());
        $this->assertCount(1, $t->requests);

        $this->now += 1; // 3540 s: exactly 60 s left, renewed
        $this->assertSame('tok-2', $p->token());
        $this->assertCount(2, $t->requests);
    }

    public function testShortLivedTokenIsNeverServedFromCache(): void
    {
        $t = new FakeTransport([self::ok('a', 50), self::ok('b', 50)]);
        $p = $this->provider($t);

        $this->assertSame('a', $p->token());
        $this->assertSame('b', $p->token());
        $this->assertCount(2, $t->requests);
    }

    public function testMissingExpiresInIsNotCached(): void
    {
        $t = new FakeTransport([self::ok('a', null), self::ok('b', null)]);
        $p = $this->provider($t);

        $this->assertSame('a', $p->token());
        $this->assertSame('b', $p->token());
    }

    public function testInvalidExpiresInIsNotCached(): void
    {
        $t = new FakeTransport([
            FakeTransport::json(200, ['access_token' => 'a', 'expires_in' => 'soon']),
            FakeTransport::json(200, ['access_token' => 'b', 'expires_in' => -5]),
        ]);
        $p = $this->provider($t);

        $this->assertSame('a', $p->token());
        $this->assertSame('b', $p->token());
    }

    public function testInvalidateForcesANewToken(): void
    {
        $t = new FakeTransport([self::ok('a'), self::ok('b')]);
        $p = $this->provider($t);

        $p->token();
        $p->invalidate();

        $this->assertSame('b', $p->token());
    }

    public function testNumericStringExpiresInIsAccepted(): void
    {
        $t = new FakeTransport([FakeTransport::json(200, ['access_token' => 'a', 'expires_in' => '3600'])]);
        $p = $this->provider($t);

        $p->token();
        $p->token();

        $this->assertCount(1, $t->requests);
    }

    public function testBadCredentialsDoNotLeakAndHaveAHint(): void
    {
        // An IdP that echoes the request in its error must not make it into our message.
        $t = new FakeTransport([FakeTransport::json(400, ['error' => 'invalid_grant', 'error_description' => 'bad ' . self::SECRET, 'password' => self::SECRET])]);

        try {
            $this->provider($t)->token();
            $this->fail('should fail');
        } catch (TokenUnavailableException $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
            $this->assertStringContainsString('(invalid_grant)', $e->getMessage());
            $this->assertStringContainsString('rotated', $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET, (string) $e);
        }
    }

    public function testWeirdErrorCodeIsNotEchoed(): void
    {
        $t = new FakeTransport([FakeTransport::json(500, ['error' => 'Contains Secret ' . self::SECRET])]);

        try {
            $this->provider($t)->token();
            $this->fail('should fail');
        } catch (TokenUnavailableException $e) {
            $this->assertSame('the identity provider answered HTTP 500', $e->getMessage());
        }
    }

    public function testNetworkFailure(): void
    {
        $t = new FakeTransport([FakeTransport::down('timeout')]);

        try {
            $this->provider($t)->token();
            $this->fail('should fail');
        } catch (TokenUnavailableException $e) {
            $this->assertStringContainsString('not reachable', $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public function testAnswerWithoutAccessToken(): void
    {
        foreach ([new HttpResponse(200, 'not json'), FakeTransport::json(200, ['expires_in' => 60]), FakeTransport::json(200, ['access_token' => ''])] as $answer) {
            try {
                $this->provider(new FakeTransport([$answer]))->token();
                $this->fail('should fail');
            } catch (TokenUnavailableException $e) {
                $this->assertStringContainsString('without an access_token', $e->getMessage());
            }
        }
    }

    public function testCredentialsNeedEndpoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ServiceAccountTokenProvider(new Credentials('svc', self::SECRET), new FakeTransport());
    }

    public function testTokenUrlMustBeHttp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ServiceAccountTokenProvider(new Credentials('svc', self::SECRET, 'c', 'file:///etc/passwd'), new FakeTransport());
    }

    public function testDefaultClockAndTransportConstruct(): void
    {
        $p = new ServiceAccountTokenProvider($this->creds());

        $this->assertInstanceOf(ServiceAccountTokenProvider::class, $p);
    }
}
