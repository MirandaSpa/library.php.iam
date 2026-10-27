<?php

declare(strict_types=1);

namespace Miranda\Iam\Exception;

/**
 * The identity provider did not give a token. The message never carries the credential.
 */
class TokenUnavailableException extends IamException
{}
