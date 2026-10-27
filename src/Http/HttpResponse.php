<?php

declare(strict_types=1);

namespace Miranda\Iam\Http;

final class HttpResponse
{
    /** @var int */
    private $status;
    /** @var string */
    private $body;
    /** @var array<string,string> lower-cased names */
    private $headers = [];

    /**
     * @param array<string,string> $headers
     */
    public function __construct(int $status, string $body = '', array $headers = [])
    {
        $this->status = $status;
        $this->body = $body;
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** Case-insensitive lookup. */
    public function header(string $name): ?string
    {
        $key = strtolower($name);

        return $this->headers[$key] ?? null;
    }
}
