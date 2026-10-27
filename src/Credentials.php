<?php

declare(strict_types=1);

namespace Miranda\Iam;

use Closure;
use JsonSerializable;
use LogicException;
use Miranda\Iam\Exception\CredentialsException;

/**
 * The credentials of a service account. The secret lives only inside a
 * closure, so it never shows in var_dump, print_r, var_export, json_encode,
 * string conversion or serialize(); read it with credential() when you must
 * send it to the identity provider, and nowhere else.
 */
final class Credentials implements JsonSerializable
{
    public const ENV_USERNAME = 'IAM_SERVICE_USERNAME';
    public const ENV_CREDENTIAL = 'IAM_SERVICE_CREDENTIAL';
    public const ENV_CLIENT_ID = 'IAM_CLIENT_ID';
    public const ENV_TOKEN_URL = 'IAM_TOKEN_URL';

    private const REDACTED = '[REDACTED]';

    /** @var string */
    private $username;
    /** @var Closure */
    private $secret;
    /** @var string */
    private $clientId;
    /** @var string */
    private $tokenUrl;

    public function __construct(string $username, string $credential, string $clientId = '', string $tokenUrl = '')
    {
        if ($username === '' || $credential === '') {
            throw new CredentialsException('the service account needs a username and a credential');
        }
        $this->username = $username;
        $this->secret = static function () use ($credential): string {
            return $credential;
        };
        $this->clientId = $clientId;
        $this->tokenUrl = $tokenUrl;
    }

    /**
     * Reads IAM_SERVICE_USERNAME, IAM_SERVICE_CREDENTIAL, IAM_CLIENT_ID and
     * IAM_TOKEN_URL from the array (pass getenv() or $_ENV). Username and
     * credential are required.
     *
     * @param array<string,mixed> $env
     *
     * @throws CredentialsException naming the missing variables, never a value
     */
    public static function fromEnv(array $env): self
    {
        $get = static function (string $name) use ($env): string {
            return isset($env[$name]) && is_string($env[$name]) ? $env[$name] : '';
        };
        $missing = [];
        foreach ([self::ENV_USERNAME, self::ENV_CREDENTIAL] as $name) {
            if ($get($name) === '') {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            throw new CredentialsException('missing environment variable(s): ' . implode(', ', $missing));
        }

        return new self($get(self::ENV_USERNAME), $get(self::ENV_CREDENTIAL), $get(self::ENV_CLIENT_ID), $get(self::ENV_TOKEN_URL));
    }

    /**
     * Reads a JSON file {"username": "...", "credential": "..."} (it may also
     * carry "client_id" and "token_url"). On POSIX systems the file is refused
     * when group or others can read it: chmod 600.
     *
     * @throws CredentialsException
     */
    public static function fromFile(string $path, string $clientId = '', string $tokenUrl = ''): self
    {
        $handle = is_file($path) ? @fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new CredentialsException(sprintf('cannot read the credentials file "%s"', $path));
        }
        try {
            $stat = fstat($handle);
            if (DIRECTORY_SEPARATOR !== '\\' && $stat !== false && ($stat['mode'] & 0077) !== 0) {
                throw new CredentialsException(sprintf(
                    'the credentials file "%s" is readable by group or others (mode %04o); run chmod 600 on it',
                    $path,
                    $stat['mode'] & 0777
                ));
            }
            $contents = stream_get_contents($handle, 65536);
        } finally {
            fclose($handle);
        }

        $data = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($data)) {
            throw new CredentialsException(sprintf('the credentials file "%s" is not valid JSON', $path));
        }
        $text = static function (string $key) use ($data): string {
            return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';
        };
        if ($text('username') === '' || $text('credential') === '') {
            throw new CredentialsException(sprintf('the credentials file "%s" must have "username" and "credential"', $path));
        }

        return new self(
            $text('username'),
            $text('credential'),
            $clientId !== '' ? $clientId : $text('client_id'),
            $tokenUrl !== '' ? $tokenUrl : $text('token_url')
        );
    }

    public function username(): string
    {
        return $this->username;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function tokenUrl(): string
    {
        return $this->tokenUrl;
    }

    /** The secret. Only for the request to the identity provider. */
    public function credential(): string
    {
        return ($this->secret)();
    }

    public function withEndpoint(string $clientId, string $tokenUrl): self
    {
        return new self($this->username, $this->credential(), $clientId, $tokenUrl);
    }

    public function __toString(): string
    {
        return sprintf('Credentials(username=%s, credential=%s)', $this->username, self::REDACTED);
    }

    /**
     * @return array<string,string>
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * @return array<string,string>
     */
    public function jsonSerialize(): array
    {
        return [
            'username' => $this->username,
            'credential' => self::REDACTED,
            'client_id' => $this->clientId,
            'token_url' => $this->tokenUrl,
        ];
    }

    public function __serialize(): array
    {
        throw new LogicException('Credentials cannot be serialized');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Credentials cannot be unserialized');
    }
}
