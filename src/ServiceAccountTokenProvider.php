<?php

declare(strict_types=1);

namespace Miranda\Iam;

use InvalidArgumentException;
use Miranda\Iam\Exception\TokenUnavailableException;
use Miranda\Iam\Http\CurlTransport;
use Miranda\Iam\Http\HttpRequest;
use Miranda\Iam\Http\HttpTransport;
use Miranda\Iam\Http\TransportException;

/**
 * Gets the JWT of a service account from the identity provider with the OAuth2
 * client_credentials grant, keeps it in memory and asks for a new one when it
 * expires in less than 60 seconds.
 *
 * The scope must include "profile": without it the token has no
 * preferred_username and iam answers 401 (it cannot tell which account it is).
 */
final class ServiceAccountTokenProvider
{
    public const DEFAULT_SCOPE = 'openid profile';
    /** The token is renewed when it has less than this many seconds left. */
    public const REFRESH_MARGIN_SECONDS = 60;

    /** @var Credentials */
    private $credentials;
    /** @var HttpTransport */
    private $transport;
    /** @var string */
    private $scope;
    /** @var callable */
    private $clock;
    /** @var float */
    private $timeoutSeconds;
    /** @var string|null */
    private $token;
    /** @var int */
    private $expiresAt = 0;

    /**
     * @param callable|null $clock returns the current time as a Unix timestamp; defaults to time()
     */
    public function __construct(
        Credentials $credentials,
        ?HttpTransport $transport = null,
        string $scope = self::DEFAULT_SCOPE,
        ?callable $clock = null,
        float $timeoutSeconds = 5.0
    ) {
        if ($credentials->clientId() === '' || $credentials->tokenUrl() === '') {
            throw new InvalidArgumentException('the credentials need a client id and a token URL (IAM_CLIENT_ID and IAM_TOKEN_URL)');
        }
        Endpoint::join($credentials->tokenUrl(), '');
        $this->credentials = $credentials;
        $this->transport = $transport ?? new CurlTransport();
        $this->scope = $scope;
        $this->clock = $clock ?? static function (): int {
            return time();
        };
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * A token valid for at least 60 more seconds.
     *
     * @throws TokenUnavailableException the message never carries the credential
     */
    public function token(): string
    {
        $now = (int) ($this->clock)();
        if ($this->token !== null && $this->expiresAt - $now > self::REFRESH_MARGIN_SECONDS) {
            return $this->token;
        }

        return $this->fetch($now);
    }

    /** Forgets the cached token, for example after iam answered 401 to it. */
    public function invalidate(): void
    {
        $this->token = null;
        $this->expiresAt = 0;
    }

    private function fetch(int $now): string
    {
        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->credentials->clientId(),
            'username' => $this->credentials->username(),
            'password' => $this->credentials->credential(),
            'scope' => $this->scope,
        ], '', '&', PHP_QUERY_RFC3986);

        $request = new HttpRequest('POST', $this->credentials->tokenUrl(), [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], $body, max(1, (int) round($this->timeoutSeconds * 1000)));

        try {
            $response = $this->transport->send($request);
        } catch (TransportException $e) {
            throw new TokenUnavailableException('the identity provider is not reachable: ' . $e->getMessage());
        }

        $data = json_decode($response->body(), true);
        if ($response->status() !== 200) {
            $reason = '';
            if (is_array($data) && isset($data['error']) && is_string($data['error']) && preg_match('/^[a-z_]{1,40}$/', $data['error']) === 1) {
                $reason = ' (' . $data['error'] . ')';
            }
            $hint = $response->status() === 400 || $response->status() === 401
                ? ': check the credential, it may have been rotated, or the account disabled or deleted'
                : '';

            throw new TokenUnavailableException('the identity provider answered HTTP ' . $response->status() . $reason . $hint);
        }
        if (!is_array($data) || !isset($data['access_token']) || !is_string($data['access_token']) || $data['access_token'] === '') {
            throw new TokenUnavailableException('the identity provider answered without an access_token');
        }

        $token = $data['access_token'];
        $lifetime = isset($data['expires_in']) && is_numeric($data['expires_in']) ? (int) $data['expires_in'] : 0;
        if ($lifetime > 0) {
            $this->token = $token;
            $this->expiresAt = $now + $lifetime;
        } else {
            // Without expires_in the lifetime is unknown: use the token, never cache it.
            $this->invalidate();
        }

        return $token;
    }
}
