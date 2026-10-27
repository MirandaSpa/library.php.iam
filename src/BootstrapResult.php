<?php

declare(strict_types=1);

namespace Miranda\Iam;

/** Exit code and one-line message of IamBootstrap. */
final class BootstrapResult
{
    /** @var int */
    private $exitCode;
    /** @var string */
    private $message;

    public function __construct(int $exitCode, string $message)
    {
        $this->exitCode = $exitCode;
        $this->message = $message;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function message(): string
    {
        return $this->message;
    }
}
