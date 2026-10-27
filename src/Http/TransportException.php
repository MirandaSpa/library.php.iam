<?php

declare(strict_types=1);

namespace Miranda\Iam\Http;

use Miranda\Iam\Exception\IamException;

/**
 * The request did not get an HTTP answer: connection refused, DNS, TLS or timeout.
 */
final class TransportException extends IamException
{
}
