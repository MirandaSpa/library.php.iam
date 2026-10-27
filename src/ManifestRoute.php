<?php

declare(strict_types=1);

namespace Miranda\Iam;

/**
 * Links a route to the permission it requires, or marks it public (any valid
 * token may call it, no permission needed). The path is the full path, with
 * {name} for the segments that vary, e.g. /api/v2/storage/reception/{id}.
 */
final class ManifestRoute
{
    /** @var string */
    private $method;
    /** @var string */
    private $path;
    /** @var string|null */
    private $permission;
    /** @var bool */
    private $public;

    public function __construct(string $method, string $path, ?string $permission = null, bool $public = false)
    {
        $this->method = $method;
        $this->path = $path;
        $this->permission = $permission;
        $this->public = $public;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function permission(): ?string
    {
        return $this->permission;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    /**
     * Method and path with every {param} (and :param) as {}, and without a
     * trailing slash, so routes compare by shape like iam compares them.
     */
    public function key(): string
    {
        $segments = explode('/', rtrim($this->path, '/'));
        foreach ($segments as $i => $segment) {
            if (
                (isset($segment[0]) && $segment[0] === ':')
                || (strlen($segment) > 1 && $segment[0] === '{' && substr($segment, -1) === '}')
            ) {
                $segments[$i] = '{}';
            }
        }

        return $this->method . ' ' . implode('/', $segments);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = ['method' => $this->method, 'path' => $this->path];
        if ($this->public) {
            $out['public'] = true;
        } elseif ($this->permission !== null && $this->permission !== '') {
            $out['permission'] = $this->permission;
        }

        return $out;
    }
}
