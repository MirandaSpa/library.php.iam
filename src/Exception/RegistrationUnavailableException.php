<?php

declare(strict_types=1);

namespace Miranda\Iam\Exception;

/**
 * iam could not be reached or answered with an error that may pass (network, timeout, 5xx).
 */
class RegistrationUnavailableException extends IamException
{}
