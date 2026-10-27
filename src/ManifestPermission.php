<?php

declare(strict_types=1);

namespace Miranda\Iam;

/**
 * One permission of the catalog. The id is area:resource:action, in English;
 * the label is what administrators read.
 */
final class ManifestPermission
{
    public const SCOPE_BRANCH = 'branch';
    public const SCOPE_GLOBAL = 'global';
    public const AUDIT_DENIED = 'denied';
    public const AUDIT_ALWAYS = 'always';
    public const KIND_ENTRY = 'entry';
    public const KIND_INTERNAL = 'internal';

    /** @var string */
    private $id;
    /** @var string */
    private $label;
    /** @var string */
    private $description;
    /** @var string */
    private $scope;
    /** @var string */
    private $audit;
    /** @var string */
    private $kind;

    public function __construct(
        string $id,
        string $label,
        string $description = '',
        string $scope = self::SCOPE_BRANCH,
        string $audit = self::AUDIT_DENIED,
        string $kind = self::KIND_ENTRY
    ) {
        $this->id = $id;
        $this->label = $label;
        $this->description = $description;
        $this->scope = $scope;
        $this->audit = $audit;
        $this->kind = $kind;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function audit(): string
    {
        return $this->audit;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * @return array<string,string>
     */
    public function toArray(): array
    {
        $out = ['id' => $this->id, 'label' => $this->label];
        if ($this->description !== '') {
            $out['description'] = $this->description;
        }
        $out['scope'] = $this->scope;
        $out['audit'] = $this->audit;
        $out['kind'] = $this->kind;

        return $out;
    }
}
