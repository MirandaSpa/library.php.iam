<?php

declare(strict_types=1);

namespace Miranda\Iam;

/** The counts iam answers when it accepts a manifest. */
final class RegistrationResult
{
    /** @var int */
    private $permissions;
    /** @var int */
    private $routes;

    public function __construct(int $permissions, int $routes)
    {
        $this->permissions = $permissions;
        $this->routes = $routes;
    }

    public function permissions(): int
    {
        return $this->permissions;
    }

    public function routes(): int
    {
        return $this->routes;
    }
}
