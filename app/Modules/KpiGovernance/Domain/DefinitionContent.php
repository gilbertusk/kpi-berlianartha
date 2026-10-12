<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Domain;

use App\Modules\KpiGovernance\Domain\Scoring\ScoreStrategyRegistry;

/**
 * The editable content of one KPI definition version. Drafts may be
 * incomplete; publishing requires every field listed in REQUIRED_FOR_PUBLISH
 * plus a valid whitelisted score strategy (prompt Step 06 guardrail: no
 * publish without owner, source, formula, target rule, and approver).
 */
final readonly class DefinitionContent
{
    /** @var array<string, string> column => label (also the comparison order) */
    public const FIELDS = [
        'name' => 'Nama KPI',
        'purpose' => 'Tujuan bisnis',
        'description' => 'Deskripsi',
        'process_owner' => 'Process owner',
        'data_owner' => 'Data owner',
        'evaluation_subject' => 'Level penilaian',
        'unit' => 'Satuan',
        'value_type' => 'Tipe nilai',
        'direction' => 'Arah KPI',
        'frequency' => 'Frekuensi',
        'aggregation' => 'Agregasi',
        'formula_description' => 'Formula (uraian)',
        'numerator_definition' => 'Pembilang',
        'denominator_definition' => 'Penyebut',
        'score_strategy' => 'Strategi skor',
        'score_strategy_version' => 'Versi strategi skor',
        'score_parameters' => 'Parameter skor',
        'data_source' => 'Sumber data',
        'input_method' => 'Metode input',
        'evidence_requirement' => 'Kebutuhan bukti',
        'evidence_description' => 'Uraian bukti',
        'exception_policy' => 'Kebijakan pengecualian (missing/N/A/terlambat)',
        'target_rule' => 'Aturan target',
        'approval_chain' => 'Approver / jalur approval',
        'signoff_reference' => 'Referensi sign-off',
        'effective_from' => 'Berlaku mulai',
    ];

    public const REQUIRED_FOR_PUBLISH = [
        'name', 'purpose', 'description', 'process_owner', 'data_owner', 'evaluation_subject', 'unit',
        'value_type', 'direction', 'frequency', 'aggregation', 'formula_description', 'score_strategy',
        'data_source', 'input_method', 'evidence_requirement', 'exception_policy', 'target_rule',
        'approval_chain', 'signoff_reference', 'effective_from',
    ];

    private const VOCABULARY_FIELDS = [
        'evaluation_subject' => Vocabulary::EVALUATION_SUBJECTS,
        'unit' => Vocabulary::UNITS,
        'value_type' => Vocabulary::VALUE_TYPES,
        'direction' => Vocabulary::DIRECTIONS,
        'frequency' => Vocabulary::FREQUENCIES,
        'aggregation' => Vocabulary::AGGREGATIONS,
        'input_method' => Vocabulary::INPUT_METHODS,
        'evidence_requirement' => Vocabulary::EVIDENCE_REQUIREMENTS,
    ];

    private const SHORT_TEXT_FIELDS = ['name' => 160, 'process_owner' => 160, 'data_owner' => 160, 'signoff_reference' => 255];

    /**
     * @param  array<string, mixed>  $values
     */
    private function __construct(private array $values) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $values = [];

        foreach (array_keys(self::FIELDS) as $field) {
            $value = $input[$field] ?? null;

            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }

            $values[$field] = $value;
        }

        $strategyVersion = $values['score_strategy_version'] ?? null;
        $values['score_strategy_version'] = $strategyVersion === null ? null : (int) $strategyVersion;

        return new self($values);
    }

    public function get(string $field): mixed
    {
        return $this->values[$field] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Format problems that block saving even a draft.
     *
     * @return list<string>
     */
    public function draftErrors(ScoreStrategyRegistry $strategies): array
    {
        $errors = [];

        if ($this->values['name'] === null) {
            $errors[] = 'Nama KPI wajib diisi.';
        }

        foreach (self::SHORT_TEXT_FIELDS as $field => $max) {
            if (is_string($this->values[$field]) && mb_strlen($this->values[$field]) > $max) {
                $errors[] = self::FIELDS[$field]." maksimal {$max} karakter.";
            }
        }

        foreach (self::FIELDS as $field => $label) {
            if (is_string($this->values[$field]) && mb_strlen($this->values[$field]) > 4000) {
                $errors[] = "{$label} maksimal 4000 karakter.";
            }
        }

        foreach (self::VOCABULARY_FIELDS as $field => $allowed) {
            $value = $this->values[$field];

            if ($value !== null && (! is_string($value) || ! array_key_exists($value, $allowed))) {
                $errors[] = self::FIELDS[$field].' tidak dikenal.';
            }
        }

        if ($this->values['score_strategy'] !== null
            && $strategies->find((string) $this->values['score_strategy'], $this->values['score_strategy_version']) === null) {
            $errors[] = 'Strategi skor tidak terdaftar.';
        }

        if ($this->values['score_parameters'] !== null && ! is_array($this->values['score_parameters'])) {
            $errors[] = 'Parameter skor harus berupa konfigurasi terstruktur.';
        }

        return $errors;
    }

    /**
     * Everything still missing or invalid before this version may be
     * published. Empty list = complete.
     *
     * @return list<string>
     */
    public function publishErrors(ScoreStrategyRegistry $strategies): array
    {
        $errors = $this->draftErrors($strategies);

        foreach (self::REQUIRED_FOR_PUBLISH as $field) {
            if ($this->values[$field] === null) {
                $errors[] = self::FIELDS[$field].' wajib diisi sebelum publish.';
            }
        }

        if ($this->values['evidence_requirement'] === 'required' && $this->values['evidence_description'] === null) {
            $errors[] = 'Uraian bukti wajib diisi bila bukti diwajibkan.';
        }

        $strategy = $strategies->find(
            is_string($this->values['score_strategy']) ? $this->values['score_strategy'] : null,
            $this->values['score_strategy_version'],
        );

        if ($strategy !== null) {
            if (! in_array($this->values['direction'], $strategy->supportedDirections(), true)) {
                $errors[] = 'Strategi skor tidak sesuai dengan arah KPI.';
            }

            /** @var array<string, mixed> $parameters */
            $parameters = is_array($this->values['score_parameters']) ? $this->values['score_parameters'] : [];
            array_push($errors, ...$strategy->validate($parameters));
        }

        return array_values(array_unique($errors));
    }
}
