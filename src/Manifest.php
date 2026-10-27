<?php

declare(strict_types=1);

namespace Miranda\Iam;

use Miranda\Iam\Exception\ManifestException;

/**
 * What a service declares to iam: the permissions it defines and the routes
 * that need them. Registering a manifest gives permissions to nobody: it only
 * adds to the catalog. The routes a service registers replace the ones it had.
 *
 * Build it with ManifestLoader; the rules of validate() are the ones iam and
 * library.go.iam apply.
 */
final class Manifest
{
    private const SERVICE_PATTERN = '/^[a-z0-9_-]{1,60}$/';
    private const SEGMENT_PATTERN = '/^[a-z0-9_-]+$/';
    private const PARAM_PATTERN = '/^\{[A-Za-z0-9_-]+\}$/';
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const MAX_LABEL = 255;
    private const MAX_DESCRIPTION = 500;
    private const MAX_PERMISSION_ID = 120;
    private const MAX_PATH = 255;

    /** @var string */
    private $service;
    /** @var ManifestPermission[] */
    private $permissions;
    /** @var ManifestRoute[] */
    private $routes;

    /**
     * @param ManifestPermission[] $permissions
     * @param ManifestRoute[]      $routes
     */
    public function __construct(string $service, array $permissions, array $routes = [])
    {
        $this->service = $service;
        $this->permissions = array_values($permissions);
        $this->routes = array_values($routes);
    }

    public function service(): string
    {
        return $this->service;
    }

    /**
     * @return ManifestPermission[]
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    /**
     * @return ManifestRoute[]
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Everything wrong with the manifest, all at once.
     *
     * @return string[]
     */
    public function problems(): array
    {
        $problems = [];

        if (preg_match(self::SERVICE_PATTERN, $this->service) !== 1) {
            $problems[] = sprintf('service "%s" must be lowercase letters, digits, - or _ (1 to 60)', $this->service);
        }

        $declared = [];
        foreach ($this->permissions as $p) {
            $id = $p->id();
            if (!self::isPermissionId($id)) {
                $problems[] = sprintf('permission "%s" must be area:resource:action, in lowercase (letters, digits, - and _)', $id);
            }
            if (self::length($id) > self::MAX_PERMISSION_ID) {
                $problems[] = sprintf('permission "%s" is longer than %d characters', $id, self::MAX_PERMISSION_ID);
            }
            if (isset($declared[$id])) {
                $problems[] = sprintf('permission "%s" is declared twice', $id);
            }
            $declared[$id] = true;

            if (trim($p->label()) === '') {
                $problems[] = sprintf('permission "%s" needs a label', $id);
            } elseif (self::length($p->label()) > self::MAX_LABEL) {
                $problems[] = sprintf('permission "%s": label is longer than %d characters', $id, self::MAX_LABEL);
            }
            if (self::length($p->description()) > self::MAX_DESCRIPTION) {
                $problems[] = sprintf('permission "%s": description is longer than %d characters', $id, self::MAX_DESCRIPTION);
            }
            if (!in_array($p->scope(), [ManifestPermission::SCOPE_BRANCH, ManifestPermission::SCOPE_GLOBAL], true)) {
                $problems[] = sprintf('permission "%s": scope must be branch or global', $id);
            }
            if (!in_array($p->audit(), [ManifestPermission::AUDIT_DENIED, ManifestPermission::AUDIT_ALWAYS], true)) {
                $problems[] = sprintf('permission "%s": audit must be denied or always', $id);
            }
            if (!in_array($p->kind(), [ManifestPermission::KIND_ENTRY, ManifestPermission::KIND_INTERNAL], true)) {
                $problems[] = sprintf('permission "%s": kind must be entry or internal', $id);
            }
        }

        $seen = [];
        foreach ($this->routes as $r) {
            $where = $r->method() . ' ' . $r->path();
            if (!in_array($r->method(), self::METHODS, true)) {
                $problems[] = sprintf('route %s: method must be GET, POST, PUT, PATCH or DELETE (uppercase)', $where);
            }
            foreach (self::pathProblems($r->path()) as $problem) {
                $problems[] = sprintf('route %s: %s', $where, $problem);
            }
            $key = $r->key();
            if (isset($seen[$key])) {
                $problems[] = sprintf('route %s is declared twice', $where);
            }
            $seen[$key] = true;

            $permission = $r->permission();
            $hasPermission = $permission !== null && $permission !== '';
            if ($r->isPublic() && $hasPermission) {
                $problems[] = sprintf('route %s: it is either public or needs a permission, not both', $where);
            } elseif (!$r->isPublic() && !$hasPermission) {
                $problems[] = sprintf('route %s: declare its permission, or mark it public', $where);
            } elseif (!$r->isPublic() && !isset($declared[(string) $permission])) {
                $problems[] = sprintf('route %s uses "%s", which the manifest does not declare', $where, (string) $permission);
            }
        }

        return $problems;
    }

    /**
     * @throws ManifestException listing every problem
     */
    public function validate(): void
    {
        $problems = $this->problems();
        if ($problems !== []) {
            throw new ManifestException($problems);
        }
    }

    /**
     * The JSON body of PUT /internal/manifests/{service}. The service name goes
     * in the URL, not in the body.
     *
     * @return array<string,mixed>
     */
    public function toRegistrationBody(): array
    {
        $body = ['permissions' => array_map(static function (ManifestPermission $p): array {
            return $p->toArray();
        }, $this->permissions)];
        if ($this->routes !== []) {
            $body['routes'] = array_map(static function (ManifestRoute $r): array {
                return $r->toArray();
            }, $this->routes);
        }

        return $body;
    }

    private static function isPermissionId(string $id): bool
    {
        $parts = explode(':', $id);
        if (count($parts) !== 3) {
            return false;
        }
        foreach ($parts as $part) {
            if (preg_match(self::SEGMENT_PATTERN, $part) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string[]
     */
    private static function pathProblems(string $path): array
    {
        $problems = [];
        if ($path === '' || $path[0] !== '/') {
            $problems[] = 'path must start with /';
        }
        if (preg_match('/[\s?#]/', $path) === 1) {
            $problems[] = 'path must not contain spaces, ? or #';
        }
        if (self::length($path) > self::MAX_PATH) {
            $problems[] = sprintf('path is longer than %d characters', self::MAX_PATH);
        }
        if (strpos($path, '//') !== false) {
            $problems[] = 'path must not have empty segments';
        }
        // A segment with a brace must be exactly one well-formed {param}.
        foreach (explode('/', $path) as $segment) {
            if (strpbrk($segment, '{}') !== false && preg_match(self::PARAM_PATTERN, $segment) !== 1) {
                $problems[] = sprintf('segment "%s" is not a valid {param} (letters, digits, _ and - only)', $segment);
            }
        }

        return $problems;
    }

    private static function length(string $s): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($s, 'UTF-8');
        }
        $n = preg_match_all('/./su', $s);

        return $n === false ? strlen($s) : $n;
    }
}
