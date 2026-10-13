<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain;

/**
 * Everything one subject's scorecard depends on, in a canonical form that is
 * stored with every calculation and hashed to detect whether anything
 * changed since the last one.
 */
final readonly class ScorecardInput
{
    /**
     * @param  list<KpiLineInput>  $lines  in template order
     */
    public function __construct(
        public string $subjectReference,
        public string $subjectType,
        public string $subjectCode,
        public ?string $templateReference,
        public array $lines,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subject_reference' => $this->subjectReference,
            'subject_type' => $this->subjectType,
            'subject_code' => $this->subjectCode,
            'template' => $this->templateReference,
            'lines' => array_map(static fn (KpiLineInput $line): array => $line->toArray(), $this->lines),
        ];
    }
}
