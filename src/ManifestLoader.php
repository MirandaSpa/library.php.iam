<?php

declare(strict_types=1);

namespace Miranda\Iam;

use Miranda\Iam\Exception\ManifestException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds and validates a Manifest from a permissions.yaml file or from an array.
 */
final class ManifestLoader
{
    private const MAX_FILE_BYTES = 4194304;

    /**
     * @throws ManifestException when the file cannot be read or the manifest is invalid
     */
    public static function fromFile(string $path): Manifest
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ManifestException([sprintf('cannot read the manifest file "%s"', $path)]);
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw new ManifestException([sprintf('the manifest file "%s" is bigger than 4 MiB', $path)]);
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ManifestException([sprintf('cannot read the manifest file "%s"', $path)]);
        }

        try {
            $data = Yaml::parse($contents);
        } catch (ParseException $e) {
            throw new ManifestException([sprintf('the manifest "%s" is not valid YAML: %s', $path, $e->getMessage())]);
        }
        if (!is_array($data)) {
            throw new ManifestException([sprintf('the manifest "%s" must be a YAML mapping with service, permissions and routes', $path)]);
        }

        return self::fromArray($data);
    }

    /**
     * @param array<mixed> $data keys: service, permissions, routes
     *
     * @throws ManifestException listing every problem
     */
    public static function fromArray(array $data): Manifest
    {
        $problems = [];

        $service = self::text($data, 'service', 'service', $problems);

        $permissions = [];
        foreach (self::list($data, 'permissions', $problems) as $i => $raw) {
            $where = 'permissions[' . $i . ']';
            if (!is_array($raw)) {
                $problems[] = $where . ' must be a mapping';
                continue;
            }
            $id = self::text($raw, 'id', $where . '.id', $problems);
            $name = $id !== '' ? 'permission "' . $id . '"' : $where;
            $permissions[] = new ManifestPermission(
                $id,
                self::text($raw, 'label', $name . ': label', $problems),
                self::text($raw, 'description', $name . ': description', $problems),
                self::text($raw, 'scope', $name . ': scope', $problems, ManifestPermission::SCOPE_BRANCH),
                self::text($raw, 'audit', $name . ': audit', $problems, ManifestPermission::AUDIT_DENIED),
                self::text($raw, 'kind', $name . ': kind', $problems, ManifestPermission::KIND_ENTRY)
            );
        }

        $routes = [];
        foreach (self::list($data, 'routes', $problems) as $i => $raw) {
            $where = 'routes[' . $i . ']';
            if (!is_array($raw)) {
                $problems[] = $where . ' must be a mapping';
                continue;
            }
            $public = false;
            if (array_key_exists('public', $raw) && $raw['public'] !== null) {
                if (is_bool($raw['public'])) {
                    $public = $raw['public'];
                } else {
                    $problems[] = $where . ': public must be true or false';
                }
            }
            $permission = self::text($raw, 'permission', $where . ': permission', $problems);
            $routes[] = new ManifestRoute(
                self::text($raw, 'method', $where . ': method', $problems),
                self::text($raw, 'path', $where . ': path', $problems),
                $permission === '' ? null : $permission,
                $public
            );
        }

        $manifest = new Manifest($service, $permissions, $routes);
        $problems = array_merge($problems, $manifest->problems());
        if ($problems !== []) {
            throw new ManifestException($problems);
        }

        return $manifest;
    }

    /**
     * @param array<mixed> $from
     * @param string[]     $problems
     */
    private static function text(array $from, string $key, string $label, array &$problems, string $default = ''): string
    {
        if (!array_key_exists($key, $from) || $from[$key] === null) {
            return $default;
        }
        if (!is_string($from[$key])) {
            $problems[] = $label . ' must be text';

            return $default;
        }

        return $from[$key] === '' ? $default : $from[$key];
    }

    /**
     * @param array<mixed> $from
     * @param string[]     $problems
     *
     * @return array<mixed>
     */
    private static function list(array $from, string $key, array &$problems): array
    {
        if (!array_key_exists($key, $from) || $from[$key] === null) {
            return [];
        }
        if (!is_array($from[$key])) {
            $problems[] = $key . ' must be a list';

            return [];
        }

        return array_values($from[$key]);
    }
}
