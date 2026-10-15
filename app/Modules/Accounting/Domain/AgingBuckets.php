<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

use InvalidArgumentException;

/**
 * A versioned set of aging buckets. The default version is the one the PRD
 * prescribes (§10.2): not yet due, 1–30, 31–60, 61–90, and more than 90
 * days past due. Another set needs a business decision and its own version.
 */
final readonly class AgingBuckets
{
    public const PRD_DEFAULT = [
        'version' => 'prd-10.2',
        'upper_bounds' => [30, 60, 90],
    ];

    /**
     * @param  list<int>  $upperBounds  ascending inclusive upper bounds of the overdue buckets
     */
    private function __construct(
        public string $version,
        public array $upperBounds,
    ) {}

    /**
     * @param  array<mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $version = $config['version'] ?? null;
        $bounds = $config['upper_bounds'] ?? null;

        if (! is_string($version) || preg_match('/^[a-z0-9][a-z0-9._-]{1,39}$/', $version) !== 1) {
            throw new InvalidArgumentException('Versi bucket aging tidak valid.');
        }

        if (! is_array($bounds) || $bounds === [] || ! array_is_list($bounds)) {
            throw new InvalidArgumentException('Batas bucket aging wajib diisi.');
        }

        $previous = 0;

        foreach ($bounds as $bound) {
            if (! is_int($bound) || $bound <= $previous) {
                throw new InvalidArgumentException('Batas bucket aging harus bilangan bulat yang naik.');
            }

            $previous = $bound;
        }

        return new self($version, $bounds);
    }

    /**
     * @return array<string, string> bucket key => label, in order
     */
    public function labels(): array
    {
        $labels = ['current' => 'Belum jatuh tempo'];
        $lower = 1;

        foreach ($this->upperBounds as $upper) {
            $labels["d{$lower}_{$upper}"] = "{$lower}–{$upper} hari";
            $lower = $upper + 1;
        }

        $last = $lower - 1;
        $labels["over_{$last}"] = "Lebih dari {$last} hari";

        return $labels;
    }

    public function bucketFor(int $daysPastDue): string
    {
        if ($daysPastDue <= 0) {
            return 'current';
        }

        $lower = 1;

        foreach ($this->upperBounds as $upper) {
            if ($daysPastDue <= $upper) {
                return "d{$lower}_{$upper}";
            }

            $lower = $upper + 1;
        }

        return 'over_'.($lower - 1);
    }
}
