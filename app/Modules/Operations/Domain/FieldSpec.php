<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

/**
 * One field of a record type: how it is entered, validated, and shown.
 */
final readonly class FieldSpec
{
    public const TEXT = 'text';

    public const LONG_TEXT = 'long_text';

    public const INTEGER = 'integer';

    public const DECIMAL = 'decimal';

    public const CHOICE = 'choice';

    /**
     * @param  array<string, string>  $options  value => label, for CHOICE
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $kind,
        public bool $required = true,
        public array $options = [],
        public int $maxLength = 160,
        public ?string $hint = null,
    ) {}
}
