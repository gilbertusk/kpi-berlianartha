{{-- Draft editor. $strategy: selected strategy key or '', $strategies: array<key, label> --}}
@php
    use App\Modules\KpiGovernance\Domain\Scoring\CommonScoreRules;
    use App\Modules\KpiGovernance\Domain\Scoring\RatioToTargetStrategy;
    use App\Modules\KpiGovernance\Domain\Vocabulary;
@endphp

<form wire:submit="saveDraft" class="flex flex-col gap-6">
    <fieldset class="grid gap-4 sm:grid-cols-2">
        <legend class="mb-2 text-base font-semibold">{{ __('Identitas dan kepemilikan') }}</legend>
        <x-ui.input name="form.name" :label="__('Nama KPI')" required wire:model="form.name" />
        <x-ui.select name="form.evaluation_subject" :label="__('Level penilaian')" :placeholder="__('Pilih')" :options="Vocabulary::EVALUATION_SUBJECTS" wire:model="form.evaluation_subject" />
        <x-ui.textarea name="form.purpose" :label="__('Tujuan bisnis')" wire:model="form.purpose" />
        <x-ui.textarea name="form.description" :label="__('Deskripsi')" wire:model="form.description" />
        <x-ui.input name="form.process_owner" :label="__('Process owner')" wire:model="form.process_owner" />
        <x-ui.input name="form.data_owner" :label="__('Data owner')" wire:model="form.data_owner" />
    </fieldset>

    <fieldset class="grid gap-4 sm:grid-cols-3">
        <legend class="mb-2 text-base font-semibold">{{ __('Pengukuran') }}</legend>
        <x-ui.select name="form.unit" :label="__('Satuan')" :placeholder="__('Pilih')" :options="Vocabulary::UNITS" wire:model="form.unit" />
        <x-ui.select name="form.value_type" :label="__('Tipe nilai')" :placeholder="__('Pilih')" :options="Vocabulary::VALUE_TYPES" wire:model="form.value_type" />
        <x-ui.select name="form.direction" :label="__('Arah KPI')" :placeholder="__('Pilih')" :options="Vocabulary::DIRECTIONS" wire:model="form.direction" />
        <x-ui.select name="form.frequency" :label="__('Frekuensi')" :placeholder="__('Pilih')" :options="Vocabulary::FREQUENCIES" wire:model="form.frequency" />
        <x-ui.select name="form.aggregation" :label="__('Agregasi')" :placeholder="__('Pilih')" :options="Vocabulary::AGGREGATIONS" wire:model="form.aggregation" />
        <div class="sm:col-span-3 grid gap-4 sm:grid-cols-3">
            <x-ui.textarea name="form.formula_description" :label="__('Formula (uraian untuk manusia)')" wire:model="form.formula_description" />
            <x-ui.textarea name="form.numerator_definition" :label="__('Pembilang')" wire:model="form.numerator_definition" />
            <x-ui.textarea name="form.denominator_definition" :label="__('Penyebut')" wire:model="form.denominator_definition" />
        </div>
    </fieldset>

    <fieldset class="grid gap-4 sm:grid-cols-3">
        <legend class="mb-2 text-base font-semibold">{{ __('Strategi skor (allowlist)') }}</legend>
        <div class="sm:col-span-3">
            <x-ui.select name="form.score_strategy" :label="__('Strategi')" :placeholder="__('Pilih strategi')" :options="$strategies" :hint="__('Formula tidak pernah dijalankan dari teks. Hanya strategi terdaftar dengan parameter tervalidasi.')" wire:model.live="form.score_strategy" />
        </div>

        @if ($strategy !== '')
            <x-ui.select name="params.missing_data_rule" :label="__('Aturan missing data')" :placeholder="__('Pilih')" :options="CommonScoreRules::MISSING_DATA_RULES" wire:model="params.missing_data_rule" />
            <x-ui.select name="params.rounding_mode" :label="__('Mode pembulatan')" :placeholder="__('Pilih')" :options="CommonScoreRules::ROUNDING_MODES" wire:model="params.rounding_mode" />
            <x-ui.select name="params.rounding_scale" :label="__('Desimal pembulatan')" :placeholder="__('Pilih')" :options="['0' => '0', '1' => '1', '2' => '2', '3' => '3', '4' => '4']" wire:model="params.rounding_scale" />
        @endif

        @if (in_array($strategy, ['ratio_to_target', 'inverse_ratio_to_target'], true))
            <x-ui.input name="params.score_floor" :label="__('Batas bawah skor')" wire:model="params.score_floor" />
            <x-ui.input name="params.score_cap" :label="__('Batas atas skor')" wire:model="params.score_cap" />
            <x-ui.select name="params.zero_target_rule" :label="__('Jika target nol')" :placeholder="__('Pilih')" :options="RatioToTargetStrategy::ZERO_TARGET_RULES" wire:model="params.zero_target_rule" />
            @if ($strategy === 'inverse_ratio_to_target')
                <x-ui.select name="params.zero_actual_rule" :label="__('Jika aktual nol')" :placeholder="__('Pilih')" :options="RatioToTargetStrategy::ZERO_ACTUAL_RULES" wire:model="params.zero_actual_rule" />
            @endif
        @elseif ($strategy === 'range_band')
            <div class="sm:col-span-2">
                <x-ui.textarea name="bandsText" :label="__('Rentang (satu baris: min;max;skor)')" :rows="4" :hint="__('Rentang setengah terbuka [min, max), berurutan dan tidak tumpang tindih.')" wire:model="bandsText" />
            </div>
            <x-ui.input name="params.out_of_band_score" :label="__('Skor di luar rentang')" wire:model="params.out_of_band_score" />
        @elseif ($strategy === 'binary')
            <x-ui.input name="params.achieved_score" :label="__('Skor tercapai')" wire:model="params.achieved_score" />
            <x-ui.input name="params.not_achieved_score" :label="__('Skor tidak tercapai')" wire:model="params.not_achieved_score" />
        @elseif ($strategy === 'milestone_stage')
            <div class="sm:col-span-3">
                <x-ui.textarea name="stagesText" :label="__('Tahap (satu baris: label;skor)')" :rows="4" wire:model="stagesText" />
            </div>
        @endif
    </fieldset>

    <fieldset class="grid gap-4 sm:grid-cols-2">
        <legend class="mb-2 text-base font-semibold">{{ __('Sumber, bukti, dan pengecualian') }}</legend>
        <x-ui.textarea name="form.data_source" :label="__('Sumber data (system/document of record)')" wire:model="form.data_source" />
        <x-ui.select name="form.input_method" :label="__('Metode input')" :placeholder="__('Pilih')" :options="Vocabulary::INPUT_METHODS" wire:model="form.input_method" />
        <x-ui.select name="form.evidence_requirement" :label="__('Kebutuhan bukti')" :placeholder="__('Pilih')" :options="Vocabulary::EVIDENCE_REQUIREMENTS" wire:model="form.evidence_requirement" />
        <x-ui.textarea name="form.evidence_description" :label="__('Uraian bukti')" wire:model="form.evidence_description" />
        <div class="sm:col-span-2">
            <x-ui.textarea name="form.exception_policy" :label="__('Kebijakan pengecualian (missing, N/A, terlambat)')" wire:model="form.exception_policy" />
        </div>
    </fieldset>

    <fieldset class="grid gap-4 sm:grid-cols-2">
        <legend class="mb-2 text-base font-semibold">{{ __('Target, approval, dan governance') }}</legend>
        <x-ui.textarea name="form.target_rule" :label="__('Aturan target')" wire:model="form.target_rule" />
        <x-ui.textarea name="form.approval_chain" :label="__('Approver / jalur approval')" wire:model="form.approval_chain" />
        <x-ui.input name="form.signoff_reference" :label="__('Referensi sign-off bisnis')" :hint="__('Nomor dokumen/notulen persetujuan owner dan HR. Wajib sebelum review.')" wire:model="form.signoff_reference" />
        <x-ui.input name="form.effective_from" type="date" :label="__('Berlaku mulai')" wire:model="form.effective_from" />
    </fieldset>

    <div>
        <x-ui.button type="submit">{{ __('Simpan draft') }}</x-ui.button>
    </div>
</form>
