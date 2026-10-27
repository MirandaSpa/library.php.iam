<?php

declare(strict_types=1);

namespace Miranda\Iam\Exception;

/**
 * The service account credentials are missing, malformed or stored unsafely. The message never carries the secret.
 */
class CredentialsException extends IamException
{}
