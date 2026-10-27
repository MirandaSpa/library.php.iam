<?php

declare(strict_types=1);

namespace Miranda\Iam\Http;

use InvalidArgumentException;

final class HttpRequest
{
    /** @var string */
    private $method;
    /** @var string */
    private $url;
    /** @var array<string,string> */
    private $headers;
    /** @var string|null */
    private $body;
    /** @var int */
    private $timeoutMs;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutMs = 5000)
    {
        foreach ($headers as $name => $value) {
            if (preg_match('/[\r\n\0]/', (string) $name . (string) $value) === 1) {
                throw new InvalidArgumentException('HTTP header contains a line break or a NUL byte');
            }
        }
        if ($timeoutMs < 1) {
            throw new InvalidArgumentException('timeout must be at least 1 ms');
        }
        $this->method = strtoupper($method);
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->timeoutMs = $timeoutMs;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * @return array<string,string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Case-insensitive lookup. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0) {
                return $v;
            }
        }

        return null;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    public function timeoutMs(): int
    {
        return $this->timeoutMs;
    }
}
