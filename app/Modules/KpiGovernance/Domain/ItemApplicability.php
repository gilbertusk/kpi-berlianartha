<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Domain;

use InvalidArgumentException;

/**
 * Which members of a template's scope a KPI item applies to. Null means
 * every member. The only rule kind today is an exact (case-insensitive)
 * match on the assignment position title; new kinds are added as new keys,
 * never as free-text expressions.
 */
final readonly class ItemApplicability
{
    private const MAX_POSITIONS = 20;

    /**
     * @param  list<string>  $positions
     */
    private function __construct(public array $positions) {}

    /**
     * @param  array<string, mixed>|null  $rule
     */
    public static function fromArray(?array $rule): ?self
    {
        if ($rule === null || $rule === []) {
            return null;
        }

        if (array_keys($rule) !== ['positions'] || ! is_array($rule['positions'])) {
            throw new InvalidArgumentException('Aturan applicability hanya mendukung daftar jabatan.');
        }

        $positions = [];

        foreach ($rule['positions'] as $position) {
            $position = is_string($position) ? trim($position) : '';

            if ($position === '' || mb_strlen($position) > 120) {
                throw new InvalidArgumentException('Setiap jabatan wajib diisi dan maksimal 120 karakter.');
            }

            $positions[mb_strtolower($position)] ??= $position;
        }

        if ($positions === [] || count($positions) > self::MAX_POSITIONS) {
            throw new InvalidArgumentException('Aturan applicability berisi 1 sampai '.self::MAX_POSITIONS.' jabatan.');
        }

        return new self(array_values($positions));
    }

    /**
     * Parses "Operator, Leader Shift" style input from forms.
     */
    public static function fromList(string $commaSeparated): ?self
    {
        $positions = array_values(array_filter(array_map('trim', explode(',', $commaSeparated)), fn (string $value): bool => $value !== ''));

        return $positions === [] ? null : self::fromArray(['positions' => $positions]);
    }

    public function appliesTo(?string $positionTitle): bool
    {
        if ($positionTitle === null) {
            return false;
        }

        return in_array(mb_strtolower(trim($positionTitle)), array_map('mb_strtolower', $this->positions), true);
    }

    /**
     * @return array{positions: list<string>}
     */
    public function toArray(): array
    {
        return ['positions' => $this->positions];
    }

    public function describe(): string
    {
        return 'Jabatan: '.implode(', ', $this->positions);
    }
}
