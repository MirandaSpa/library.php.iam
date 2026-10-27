<?php

declare(strict_types=1);

namespace Miranda\Iam\Tests;

use InvalidArgumentException;
use Miranda\Iam\AuthzAnswer;
use Miranda\Iam\AuthzClient;
use Miranda\Iam\Exception\AuthzUnavailableException;
use Miranda\Iam\Http\HttpResponse;
use Miranda\Iam\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class AuthzClientTest extends TestCase
{
    private const IAM = 'https://iam.example.test/api/v2/iam';

    private function client(FakeTransport $t): AuthzClient
    {
        return new AuthzClient(self::IAM, $t);
    }

    public function testAuthorizeAllowedSendsExactRequest(): void
    {
        $t = new FakeTransport([new HttpResponse(200, '', ['x-usuario-id' => 'user-123'])]);

        $answer = $this->client($t)->authorize('get', 'https://legacy.example.test/api/empaque/voucher/7', 'Bearer abc.def.ghi', 'branch-9');

        $this->assertTrue($answer->isAllowed());
        $this->assertSame(AuthzAnswer::ALLOWED, $answer->status());
        $this->assertSame('user-123', $answer->userId());
        $this->assertSame(200, $answer->httpStatus());

        $req = $t->last();
        $this->assertSame('GET', $req->method());
        $this->assertSame(self::IAM . '/internal/authz', $req->url());
        $this->assertNull($req->body());
        $this->assertSame(500, $req->timeoutMs());
        $this->assertSame('Bearer abc.def.ghi', $req->header('Authorization'));
        $this->assertSame('GET', $req->header('X-Original-Method'));
        $this->assertSame('https://legacy.example.test/api/empaque/voucher/7', $req->header('X-Original-URL'));
        $this->assertSame('branch-9', $req->header('X-Sucursal-Id'));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function queryProvider(): array
    {
        return [
            'query' => ['/api/users?token=SECRET&x=1', '/api/users'],
            'query on full url' => ['https://legacy.example.test/api/users?access_token=SECRET', 'https://legacy.example.test/api/users'],
            'fragment' => ['/api/users#frag', '/api/users'],
            'query and fragment' => ['/api/users?a=1#frag', '/api/users'],
            'empty query' => ['/api/users?', '/api/users'],
            'no query' => ['/api/users/12', '/api/users/12'],
        ];
    }

    /**
     * @dataProvider queryProvider
     */
    public function testQueryStringIsNeverSent(string $url, string $expected): void
    {
        $t = new FakeTransport([new HttpResponse(200)]);

        $this->client($t)->authorize('POST', $url, 'Bearer x', null);

        $req = $t->last();
        $this->assertSame($expected, $req->header('X-Original-URL'));
        $this->assertStringNotContainsString('SECRET', implode("\n", $req->headers()) . $req->url());
        $this->assertStringNotContainsString('?', (string) $req->header('X-Original-URL'));
    }

    public function testOptionalHeadersAreOmitted(): void
    {
        $t = new FakeTransport([new HttpResponse(401)]);

        $answer = $this->client($t)->authorize('GET', '/api/x', null, null);

        $this->assertNull($t->last()->header('Authorization'));
        $this->assertNull($t->last()->header('X-Sucursal-Id'));
        $this->assertTrue($answer->isUnauthenticated());

        $this->client(new FakeTransport([new HttpResponse(401)]));
        $t2 = new FakeTransport([new HttpResponse(401)]);
        $this->client($t2)->authorize('GET', '/api/x', '', '');
        $this->assertNull($t2->last()->header('Authorization'));
        $this->assertNull($t2->last()->header('X-Sucursal-Id'));
    }

    /**
     * @return array<string,array{0:\Throwable|HttpResponse,1:string,2:?int,3:?string}>
     */
    public static function answerProvider(): array
    {
        return [
            '200' => [new HttpResponse(200, '', ['X-Usuario-Id' => 'u1']), AuthzAnswer::ALLOWED, 200, 'u1'],
            '200 without user id' => [new HttpResponse(200), AuthzAnswer::ALLOWED, 200, null],
            '401' => [new HttpResponse(401, '', ['X-Usuario-Id' => 'ignored']), AuthzAnswer::UNAUTHENTICATED, 401, null],
            '403' => [new HttpResponse(403), AuthzAnswer::FORBIDDEN, 403, null],
            '503' => [new HttpResponse(503), AuthzAnswer::UNAVAILABLE, 503, null],
            '500' => [new HttpResponse(500), AuthzAnswer::UNAVAILABLE, 500, null],
            '400' => [new HttpResponse(400), AuthzAnswer::UNAVAILABLE, 400, null],
            '302' => [new HttpResponse(302), AuthzAnswer::UNAVAILABLE, 302, null],
            'timeout' => [FakeTransport::down('timeout'), AuthzAnswer::UNAVAILABLE, null, null],
        ];
    }

    /**
     * @dataProvider answerProvider
     *
     * @param \Throwable|HttpResponse $transportAnswer
     */
    public function testAuthorizeAnswers($transportAnswer, string $status, ?int $http, ?string $userId): void
    {
        $answer = $this->client(new FakeTransport([$transportAnswer]))->authorize('GET', '/api/x', 'Bearer t', 'b');

        $this->assertSame($status, $answer->status());
        $this->assertSame($http, $answer->httpStatus());
        $this->assertSame($userId, $answer->userId());
        $this->assertSame($status === AuthzAnswer::ALLOWED, $answer->isAllowed());
        $this->assertSame($status === AuthzAnswer::UNAVAILABLE, $answer->isUnavailable());
        $this->assertSame($status === AuthzAnswer::FORBIDDEN, $answer->isForbidden());
        $this->assertSame($status === AuthzAnswer::UNAUTHENTICATED, $answer->isUnauthenticated());
    }

    public function testNeverRetries(): void
    {
        $t = new FakeTransport([FakeTransport::down(), new HttpResponse(200)]);

        $answer = $this->client($t)->authorize('GET', '/api/x', 'Bearer t', null);

        $this->assertTrue($answer->isUnavailable());
        $this->assertCount(1, $t->requests);
    }

    public function testHeaderWithLineBreakIsNotSent(): void
    {
        $t = new FakeTransport();

        $answer = $this->client($t)->authorize('GET', '/api/x', "Bearer a\r\nX-Evil: 1", null);

        $this->assertTrue($answer->isUnavailable());
        $this->assertCount(0, $t->requests);
    }

    public function testCheckSendsExactRequest(): void
    {
        $t = new FakeTransport([FakeTransport::json(200, ['allowed' => true])]);

        $this->assertTrue($this->client($t)->check('user-1', 'storage:packing:view', 'branch-2'));

        $req = $t->last();
        $this->assertSame('POST', $req->method());
        $this->assertSame(self::IAM . '/internal/check', $req->url());
        $this->assertSame('application/json', $req->header('Content-Type'));
        $this->assertSame(500, $req->timeoutMs());
        $this->assertSame(['user_id' => 'user-1', 'permission' => 'storage:packing:view', 'branch_id' => 'branch-2'], json_decode((string) $req->body(), true));
    }

    public function testCheckWithoutBranchOmitsIt(): void
    {
        $t = new FakeTransport([FakeTransport::json(200, ['allowed' => false]), FakeTransport::json(200, ['allowed' => false])]);

        $this->assertFalse($this->client($t)->check('user-1', 'it:helpdesk:view', null));
        $this->assertFalse($this->client($t)->check('user-1', 'it:helpdesk:view', ''));

        foreach ($t->requests as $req) {
            $this->assertSame(['user_id' => 'user-1', 'permission' => 'it:helpdesk:view'], json_decode((string) $req->body(), true));
        }
    }

    /**
     * @return array<string,array{0:\Throwable|HttpResponse}>
     */
    public static function checkFailures(): array
    {
        return [
            '500' => [new HttpResponse(500)],
            '503' => [new HttpResponse(503)],
            '404' => [new HttpResponse(404)],
            '400' => [new HttpResponse(400)],
            'timeout' => [FakeTransport::down('timeout')],
            'garbage' => [new HttpResponse(200, 'nope')],
            'allowed not a bool' => [FakeTransport::json(200, ['allowed' => 'yes'])],
            'no allowed' => [FakeTransport::json(200, [])],
        ];
    }

    /**
     * @dataProvider checkFailures
     *
     * @param \Throwable|HttpResponse $answer
     */
    public function testCheckThrowsInsteadOfDenying($answer): void
    {
        $this->expectException(AuthzUnavailableException::class);
        $this->client(new FakeTransport([$answer]))->check('user-1', 'a:b:c', null);
    }

    public function testCheckRequiresUserAndPermission(): void
    {
        foreach ([['', 'a:b:c'], ['u', '']] as [$user, $perm]) {
            try {
                $this->client(new FakeTransport())->check($user, $perm, null);
                $this->fail('should refuse');
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBadIamUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuthzClient('not a url', new FakeTransport());
    }

    public function testCustomTimeout(): void
    {
        $t = new FakeTransport([new HttpResponse(200)]);

        (new AuthzClient(self::IAM, $t, 250))->authorize('GET', '/x');

        $this->assertSame(250, $t->last()->timeoutMs());
    }
}
