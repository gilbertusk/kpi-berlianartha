<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 07 — input rules of a KPI definition (KPI_DEFINITION_TEMPLATE.md §D:
 * "Decimal precision" and "Minimum/maximum valid input"). Targets, and later
 * actuals, are validated against them. The columns join the content guard so
 * they stay immutable once a version leaves draft.
 */
return new class extends Migration
{
    private const BASE_CONTENT_COLUMNS = [
        'kpi_definition_id', 'version_number', 'name', 'purpose', 'description', 'process_owner', 'data_owner',
        'evaluation_subject', 'unit', 'value_type', 'direction', 'frequency', 'aggregation', 'formula_description',
        'numerator_definition', 'denominator_definition', 'score_strategy', 'score_strategy_version',
        'score_parameters', 'data_source', 'input_method', 'evidence_requirement', 'evidence_description',
        'exception_policy', 'target_rule', 'approval_chain', 'signoff_reference', 'effective_from',
        'change_reason', 'previous_version_id',
    ];

    private const INPUT_RULE_COLUMNS = ['decimal_places', 'min_valid_value', 'max_valid_value'];

    public function up(): void
    {
        Schema::table('kpi_definition_versions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('decimal_places')->nullable();
            // Canonical decimal strings, like score parameters (ADR-0008).
            $table->string('min_valid_value', 24)->nullable();
            $table->string('max_valid_value', 24)->nullable();
        });

        $this->replaceContentGuard([...self::BASE_CONTENT_COLUMNS, ...self::INPUT_RULE_COLUMNS]);
    }

    public function down(): void
    {
        $this->replaceContentGuard(self::BASE_CONTENT_COLUMNS);

        Schema::table('kpi_definition_versions', function (Blueprint $table): void {
            $table->dropColumn(self::INPUT_RULE_COLUMNS);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function replaceContentGuard(array $columns): void
    {
        $list = implode(', ', $columns);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS kpi_definition_versions_content_guard ON kpi_definition_versions');
            DB::statement("CREATE TRIGGER kpi_definition_versions_content_guard BEFORE UPDATE OF {$list} ON kpi_definition_versions FOR EACH ROW WHEN (OLD.status <> 'draft') EXECUTE FUNCTION reject_kpi_governance_change()");

            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS kpi_definition_versions_content_guard');
        DB::statement("CREATE TRIGGER kpi_definition_versions_content_guard BEFORE UPDATE OF {$list} ON kpi_definition_versions WHEN OLD.status <> 'draft' BEGIN SELECT RAISE(ABORT, 'KPI governance record is immutable in its current status'); END;");
    }
};
