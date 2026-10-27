<?php

declare(strict_types=1);

namespace Miranda\Iam;

use InvalidArgumentException;
use Miranda\Iam\Exception\RegistrationRejectedException;
use Miranda\Iam\Exception\RegistrationUnavailableException;
use Miranda\Iam\Http\CurlTransport;
use Miranda\Iam\Http\HttpRequest;
use Miranda\Iam\Http\HttpTransport;
use Miranda\Iam\Http\TransportException;

/**
 * Registers a manifest in iam (PUT {iamUrl}/internal/manifests/{service}).
 * The call is idempotent: permissions are added or updated and the routes of
 * the service are replaced. It adds to the catalog and gives permissions to nobody.
 *
 * $iamUrl is the base of the iam API without /internal, for example
 * https://iam.example.test/api/v2/iam.
 */
final class ManifestRegistrar
{
    /** Statuses with which iam says "this manifest is wrong": retrying is useless. */
    private const REJECTED = [400, 409, 422];
    private const MAX_MESSAGE = 1000;

    /** @var HttpTransport */
    private $transport;
    /** @var float */
    private $timeoutSeconds;

    public function __construct(?HttpTransport $transport = null, float $timeoutSeconds = 5.0)
    {
        $this->transport = $transport ?? new CurlTransport();
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * One attempt. The manifest is validated first, so a mistake never reaches iam.
     *
     * @throws Exception\ManifestException              the manifest is invalid
     * @throws RegistrationRejectedException            iam answered 400, 409 or 422
     * @throws RegistrationUnavailableException         network, timeout, 5xx or an unexpected answer
     */
    public function register(Manifest $manifest, string $iamUrl): RegistrationResult
    {
        $manifest->validate();
        $url = Endpoint::join($iamUrl, '/internal/manifests/' . rawurlencode($manifest->service()));
        $body = json_encode($manifest->toRegistrationBody(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RegistrationRejectedException('the manifest cannot be encoded as JSON');
        }

        $request = new HttpRequest('PUT', $url, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $body, max(1, (int) round($this->timeoutSeconds * 1000)));

        try {
            $response = $this->transport->send($request);
        } catch (TransportException $e) {
            throw new RegistrationUnavailableException('iam is not reachable: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->status();
        if ($status >= 200 && $status <= 299) {
            $data = json_decode($response->body(), true);
            if (!is_array($data) || !isset($data['permissions'], $data['routes']) || !is_int($data['permissions']) || !is_int($data['routes'])) {
                throw new RegistrationUnavailableException('iam answered HTTP ' . $status . ' with a body this library does not understand');
            }

            return new RegistrationResult($data['permissions'], $data['routes']);
        }
        if (in_array($status, self::REJECTED, true)) {
            throw new RegistrationRejectedException(self::rejection($status, $response->body()));
        }

        throw new RegistrationUnavailableException('iam answered HTTP ' . $status);
    }

    /**
     * Retries only when iam is unavailable (it may be starting), with a fixed
     * wait. A rejection stops at once: the manifest has to change.
     *
     * @param callable|null $sleep   receives the seconds to wait; defaults to sleep()
     * @param callable|null $onRetry receives (int $attempt, RegistrationUnavailableException $error, int $delaySeconds), for logging
     *
     * @throws Exception\ManifestException
     * @throws RegistrationRejectedException
     * @throws RegistrationUnavailableException after the last attempt
     */
    public function registerWithRetry(
        Manifest $manifest,
        string $iamUrl,
        int $maxAttempts,
        int $delaySeconds,
        ?callable $sleep = null,
        ?callable $onRetry = null
    ): RegistrationResult {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be at least 1');
        }
        if ($delaySeconds < 0) {
            throw new InvalidArgumentException('delaySeconds cannot be negative');
        }
        if ($sleep === null) {
            $sleep = static function (int $seconds): void {
                sleep($seconds);
            };
        }

        $last = null;
        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            try {
                return $this->register($manifest, $iamUrl);
            } catch (RegistrationUnavailableException $e) {
                $last = $e;
                if ($attempt < $maxAttempts) {
                    if ($onRetry !== null) {
                        $onRetry($attempt, $e, $delaySeconds);
                    }
                    $sleep($delaySeconds);
                }
            }
        }

        throw new RegistrationUnavailableException(
            sprintf('iam is unavailable after %d attempt(s): %s', $maxAttempts, $last !== null ? $last->getMessage() : ''),
            0,
            $last
        );
    }

    private static function rejection(int $status, string $body): string
    {
        $message = 'iam rejected the manifest (HTTP ' . $status . ')';
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return $message;
        }
        $details = [];
        if (isset($data['detail']) && is_string($data['detail']) && $data['detail'] !== '') {
            $details[] = $data['detail'];
        }
        if (isset($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $error) {
                if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
                    $location = isset($error['location']) && is_string($error['location']) ? $error['location'] . ': ' : '';
                    $details[] = $location . $error['message'];
                }
            }
        }
        if ($details !== []) {
            $message .= ': ' . implode('; ', $details);
        }
        // Control characters out, and a hard cap: this ends up in logs.
        $message = (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $message);

        return strlen($message) > self::MAX_MESSAGE ? substr($message, 0, self::MAX_MESSAGE) . '...' : $message;
    }
}
