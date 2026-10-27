<?php

declare(strict_types=1);

namespace Miranda\Iam;

use InvalidArgumentException;
use Miranda\Iam\Exception\AuthzUnavailableException;
use Miranda\Iam\Http\CurlTransport;
use Miranda\Iam\Http\HttpRequest;
use Miranda\Iam\Http\HttpTransport;
use Miranda\Iam\Http\TransportException;

/**
 * Asks iam what a user or a request may do. Short timeout (500 ms by default)
 * and no retries: the caller decides what an unanswered question means.
 *
 * $iamUrl is the base of the iam API without /internal, for example
 * https://iam.example.test/api/v2/iam.
 */
final class AuthzClient
{
    /** @var string */
    private $iamUrl;
    /** @var HttpTransport */
    private $transport;
    /** @var int */
    private $timeoutMs;

    public function __construct(string $iamUrl, ?HttpTransport $transport = null, int $timeoutMs = 500)
    {
        Endpoint::join($iamUrl, ''); // fails early on a bad URL
        $this->iamUrl = $iamUrl;
        $this->transport = $transport ?? new CurlTransport();
        $this->timeoutMs = $timeoutMs;
    }

    /**
     * Does the user hold the permission, on the branch when the permission is
     * scoped by branch? (POST /internal/check.)
     *
     * An unanswered question is an exception, never false: a failure to ask is
     * not a denial.
     *
     * @throws InvalidArgumentException  userId or permissionId is empty
     * @throws AuthzUnavailableException iam did not answer or answered with an error
     */
    public function check(string $userId, string $permissionId, ?string $branchId = null): bool
    {
        if ($userId === '' || $permissionId === '') {
            throw new InvalidArgumentException('userId and permissionId are required');
        }
        $payload = ['user_id' => $userId, 'permission' => $permissionId];
        if ($branchId !== null && $branchId !== '') {
            $payload['branch_id'] = $branchId;
        }
        $body = json_encode($payload);
        if ($body === false) {
            throw new InvalidArgumentException('the check cannot be encoded as JSON');
        }

        try {
            $response = $this->transport->send(new HttpRequest('POST', Endpoint::join($this->iamUrl, '/internal/check'), [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $body, $this->timeoutMs));
        } catch (TransportException $e) {
            throw new AuthzUnavailableException('iam is not reachable: ' . $e->getMessage(), 0, $e);
        }

        if ($response->status() !== 200) {
            throw new AuthzUnavailableException('iam answered HTTP ' . $response->status());
        }
        $data = json_decode($response->body(), true);
        if (!is_array($data) || !isset($data['allowed']) || !is_bool($data['allowed'])) {
            throw new AuthzUnavailableException('iam answered the check with a body this library does not understand');
        }

        return $data['allowed'];
    }

    /**
     * May this request go through? (GET /internal/authz.) Never throws on iam
     * errors: a failure is an "unavailable" answer. The query string and the
     * fragment of $url are dropped before sending: they can carry tokens or
     * personal data and iam only needs the path.
     *
     * @param string|null $authorization the Authorization header of the request, with or without Bearer
     * @param string|null $branchId      the X-Sucursal-Id of the request
     */
    public function authorize(string $method, string $url, ?string $authorization = null, ?string $branchId = null): AuthzAnswer
    {
        $headers = [
            'Accept' => 'application/json',
            'X-Original-Method' => strtoupper($method),
            'X-Original-URL' => self::withoutQuery($url),
        ];
        if ($authorization !== null && $authorization !== '') {
            $headers['Authorization'] = $authorization;
        }
        if ($branchId !== null && $branchId !== '') {
            $headers['X-Sucursal-Id'] = $branchId;
        }

        try {
            $response = $this->transport->send(new HttpRequest('GET', Endpoint::join($this->iamUrl, '/internal/authz'), $headers, null, $this->timeoutMs));
        } catch (TransportException $e) {
            return AuthzAnswer::unavailable();
        } catch (InvalidArgumentException $e) {
            // A header with a line break: the request is not sent.
            return AuthzAnswer::unavailable();
        }

        return AuthzAnswer::fromHttp($response->status(), $response->header('X-Usuario-Id'));
    }

    private static function withoutQuery(string $url): string
    {
        $cut = strcspn($url, '?#');

        return substr($url, 0, $cut);
    }
}
