<?php

declare(strict_types=1);

namespace Miranda\Iam\Http;

/**
 * The only thing this library needs from an HTTP client. Implement it to plug
 * Guzzle, Symfony HttpClient or anything else; CurlTransport is the default.
 */
interface HttpTransport
{
    /**
     * Sends the request and returns the answer, whatever its status code.
     *
     * @throws TransportException when there is no HTTP answer (network error, timeout)
     */
    public function send(HttpRequest $request): HttpResponse;
}
