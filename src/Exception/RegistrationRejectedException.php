<?php

declare(strict_types=1);

namespace Miranda\Iam\Exception;

/**
 * iam refused the manifest (400, 409 or 422). Retrying will not help: the manifest must change.
 */
class RegistrationRejectedException extends IamException
{}
