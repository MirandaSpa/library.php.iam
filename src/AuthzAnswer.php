<?php

declare(strict_types=1);

namespace Miranda\Iam;

/** What iam answered to "may this request go through?". */
final class AuthzAnswer
{
    public const ALLOWED = 'allowed';
    public const UNAUTHENTICATED = 'unauthenticated';
    public const FORBIDDEN = 'forbidden';
    public const UNAVAILABLE = 'unavailable';

    /** @var string */
    private $status;
    /** @var string|null */
    private $userId;
    /** @var int|null */
    private $httpStatus;

    private function __construct(string $status, ?string $userId, ?int $httpStatus)
    {
        $this->status = $status;
        $this->userId = $userId;
        $this->httpStatus = $httpStatus;
    }

    /** Maps the HTTP status of iam: 200, 401 and 403 mean something, anything else is unavailable. */
    public static function fromHttp(int $httpStatus, ?string $userId): self
    {
        switch ($httpStatus) {
            case 200:
                return new self(self::ALLOWED, $userId !== null && $userId !== '' ? $userId : null, 200);
            case 401:
                return new self(self::UNAUTHENTICATED, null, 401);
            case 403:
                return new self(self::FORBIDDEN, null, 403);
            default:
                return new self(self::UNAVAILABLE, null, $httpStatus);
        }
    }

    /** iam gave no answer (network, timeout, bad request on our side). */
    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, null, null);
    }

    public function status(): string
    {
        return $this->status;
    }

    /** X-Usuario-Id: the internal id of the user (or service account) taken from the token. Only when allowed. */
    public function userId(): ?string
    {
        return $this->userId;
    }

    /** The status code iam answered, or null when it did not answer. */
    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function isAllowed(): bool
    {
        return $this->status === self::ALLOWED;
    }

    public function isUnauthenticated(): bool
    {
        return $this->status === self::UNAUTHENTICATED;
    }

    public function isForbidden(): bool
    {
        return $this->status === self::FORBIDDEN;
    }

    public function isUnavailable(): bool
    {
        return $this->status === self::UNAVAILABLE;
    }
}
