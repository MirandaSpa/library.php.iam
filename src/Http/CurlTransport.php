<?php

declare(strict_types=1);

namespace Miranda\Iam\Http;

/**
 * Default transport, on ext-curl. It never follows redirects, only speaks
 * http(s) and verifies TLS certificates.
 */
final class CurlTransport implements HttpTransport
{
    /** Answers are small JSON documents; anything bigger is cut. */
    private const MAX_BODY_BYTES = 1048576;

    public function send(HttpRequest $request): HttpResponse
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new TransportException('could not start the HTTP client');
        }

        $headerLines = [];
        foreach ($request->headers() as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $body = '';
        $tooBig = false;

        curl_setopt_array($ch, [
            CURLOPT_URL => $request->url(),
            CURLOPT_CUSTOMREQUEST => $request->method(),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => $request->timeoutMs(),
            CURLOPT_TIMEOUT_MS => $request->timeoutMs(),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $responseHeaders[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooBig): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY_BYTES) {
                    $tooBig = true;

                    return 0; // aborts the transfer
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        if ($request->body() !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request->body());
        }

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($tooBig) {
            throw new TransportException('the answer is too big');
        }
        if ($ok === false || $errno !== 0) {
            // curl_error() text never includes the request body or headers.
            throw new TransportException('request failed (curl error ' . $errno . ')');
        }

        return new HttpResponse($status, $body, $responseHeaders);
    }
}
