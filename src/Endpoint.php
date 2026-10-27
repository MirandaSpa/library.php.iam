<?php

declare(strict_types=1);

namespace Miranda\Iam;

use InvalidArgumentException;

/**
 * @internal Joins the iam base URL (for example https://iam.example.test/api/v2/iam)
 *           with a path, checking that it is an http(s) URL without credentials.
 */
final class Endpoint
{
    public static function join(string $baseUrl, string $path): string
    {
        $parts = parse_url($baseUrl);
        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('the iam URL must be an absolute http(s) URL without credentials, query or fragment');
        }

        return rtrim($baseUrl, '/') . $path;
    }
}
