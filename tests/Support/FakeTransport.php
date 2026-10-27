<?php

declare(strict_types=1);

namespace Miranda\Iam\Tests\Support;

use LogicException;
use Miranda\Iam\Http\HttpRequest;
use Miranda\Iam\Http\HttpResponse;
use Miranda\Iam\Http\HttpTransport;
use Miranda\Iam\Http\TransportException;

/**
 * Records every request and answers from a queue: an HttpResponse is returned,
 * a Throwable is thrown. When the queue is empty the test fails loudly.
 */
final class FakeTransport implements HttpTransport
{
    /** @var HttpRequest[] */
    public $requests = [];
    /** @var array<HttpResponse|\Throwable> */
    private $queue = [];

    /**
     * @param array<HttpResponse|\Throwable> $queue
     */
    public function __construct(array $queue = [])
    {
        $this->queue = array_values($queue);
    }

    public function push($answer): self
    {
        $this->queue[] = $answer;

        return $this;
    }

    public static function json(int $status, array $data, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($data), $headers);
    }

    public static function down(string $message = 'connection refused'): TransportException
    {
        return new TransportException($message);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        if ($this->queue === []) {
            throw new LogicException('FakeTransport: no more queued answers');
        }
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function last(): HttpRequest
    {
        if ($this->requests === []) {
            throw new LogicException('FakeTransport: no request was sent');
        }

        return $this->requests[count($this->requests) - 1];
    }
}
