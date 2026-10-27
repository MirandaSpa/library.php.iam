<?php

declare(strict_types=1);

namespace Miranda\Iam\Exception;

/**
 * The manifest is invalid. It lists every problem found, not only the first one.
 */
class ManifestException extends IamException
{
    /** @var string[] */
    private $problems;

    /**
     * @param string[] $problems
     */
    public function __construct(array $problems)
    {
        $this->problems = array_values($problems);
        parent::__construct('invalid manifest: ' . implode('; ', $this->problems));
    }

    /**
     * @return string[]
     */
    public function problems(): array
    {
        return $this->problems;
    }
}
