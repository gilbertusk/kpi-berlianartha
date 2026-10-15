<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Types;

use App\Modules\Operations\Domain\CostComponent;
use App\Modules\Operations\Domain\Fields;
use App\Modules\Operations\Domain\FieldSpec;
use App\Modules\Operations\Domain\RecordType;
use InvalidArgumentException;

/**
 * One cost component of one costing, estimated against the actual of the
 * production it was made for. The costing version names the assumptions the
 * estimate used; a comparison never mixes versions, and the estimate is
 * never a production actual. Cost figures are restricted: HR Performance
 * does not read them.
 */
final class CostingComparisonType implements RecordType
{
    public function key(): string
    {
        return 'costing_comparison';
    }

    public function label(): string
    {
        return 'Costing vs aktual produksi';
    }

    public function description(): string
    {
        return 'Estimasi costing satu komponen (material, waktu mesin, tenaga kerja) terhadap aktual produksinya, per versi asumsi.';
    }

    public function fields(): array
    {
        return [
            new FieldSpec('costing_reference', 'Referensi costing', FieldSpec::TEXT, maxLength: 50),
            new FieldSpec('costing_version', 'Versi asumsi costing', FieldSpec::TEXT, maxLength: 30, hint: 'Versi asumsi yang dipakai estimasi; perbandingan tidak mencampur versi'),
            new FieldSpec('bom_reference', 'Referensi BOM', FieldSpec::TEXT, required: false, maxLength: 60),
            new FieldSpec('routing_reference', 'Referensi routing', FieldSpec::TEXT, required: false, maxLength: 60),
            new FieldSpec('production_reference', 'Referensi produksi (work order)', FieldSpec::TEXT, maxLength: 50),
            new FieldSpec('component', 'Komponen', FieldSpec::CHOICE, options: CostComponent::options()),
            new FieldSpec('measure_unit', 'Satuan', FieldSpec::TEXT, maxLength: 20, hint: 'Satuan yang sama untuk estimasi dan aktual, misalnya IDR atau jam'),
            new FieldSpec('estimated', 'Estimasi', FieldSpec::DECIMAL),
            new FieldSpec('actual', 'Aktual produksi', FieldSpec::DECIMAL),
        ];
    }

    public function normalize(array $input): array
    {
        $values = Fields::normalize($this->fields(), $input);

        foreach (['costing_reference', 'costing_version', 'production_reference'] as $field) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', (string) $values[$field]) !== 1) {
                throw new InvalidArgumentException('Referensi hanya boleh berisi huruf, angka, titik, garis miring, garis bawah, dan tanda hubung.');
            }
        }

        return $values;
    }

    public function sourceReference(array $attributes): string
    {
        return strtoupper(implode('|', [$attributes['costing_reference'], $attributes['costing_version'], $attributes['production_reference'], $attributes['component']]));
    }

    public function readerRoles(): array
    {
        return ['management', 'department_head', 'team_leader', 'auditor'];
    }
}
