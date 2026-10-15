<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Types;

use App\Modules\Operations\Domain\Fields;
use App\Modules\Operations\Domain\FieldSpec;
use App\Modules\Operations\Domain\RecordType;
use App\Modules\Operations\Domain\RevisionCategory;

/**
 * One revision of an artwork, with the reason category that decides whether
 * it is attributed to the Design team. Design A and Design B use the same
 * type; their records stay apart by team (DEC-001 leaves their difference
 * open, so no different workflow is assumed).
 */
final class DesignRevisionType implements RecordType
{
    public function key(): string
    {
        return 'design_revision';
    }

    public function label(): string
    {
        return 'Revisi Design';
    }

    public function description(): string
    {
        return 'Satu revisi artwork beserta kategori penyebabnya. Hanya kategori kesalahan internal yang dibebankan ke team Design.';
    }

    public function fields(): array
    {
        return [
            new FieldSpec('job_reference', 'Referensi pekerjaan/artwork', FieldSpec::TEXT, maxLength: 80, hint: 'Nomor job, artwork, atau order di sistem sumber'),
            new FieldSpec('revision_number', 'Revisi ke-', FieldSpec::INTEGER),
            new FieldSpec('category', 'Kategori penyebab', FieldSpec::CHOICE, options: RevisionCategory::options()),
            new FieldSpec('description', 'Keterangan', FieldSpec::LONG_TEXT, maxLength: 2000),
        ];
    }

    public function normalize(array $input): array
    {
        return Fields::normalize($this->fields(), $input);
    }

    public function sourceReference(array $attributes): string
    {
        return strtoupper((string) $attributes['job_reference']).'#R'.(string) $attributes['revision_number'];
    }

    public function readerRoles(): array
    {
        return ['management', 'department_head', 'team_leader', 'hr_performance', 'auditor'];
    }
}
