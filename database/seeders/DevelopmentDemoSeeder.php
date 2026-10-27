<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\ActualEvidence\Application\ActualAccess;
use App\Modules\ActualEvidence\Application\ActualEntry;
use App\Modules\ActualEvidence\Application\ActualService;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\KpiGovernance\Application\KpiDefinitionService;
use App\Modules\KpiGovernance\Application\KpiTemplateService;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\PeriodAssignment\Application\PeriodService;
use App\Modules\PeriodAssignment\Application\PeriodSetup;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Target\Application\TargetEntry;
use App\Modules\Target\Application\TargetService;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A complete, visibly synthetic workflow for the local preview only.
 *
 * The values below are demonstration fixtures, not approved KPI definitions,
 * targets, weights, formulas, ratings, or business sign-offs.
 */
final class DevelopmentDemoSeeder extends Seeder
{
    public const PERIOD_CODE = 'DEMO-PILOT';

    private const SIGNOFF = 'LOCAL-DEMO-NOT-BUSINESS-APPROVAL';

    /**
     * @var array<string, array{template: string, kpis: array<string, string>}>
     */
    private const TEAMS = [
        'COMSUP-SALES' => [
            'template' => 'DEMO-TPL-SALES',
            'kpis' => [
                'DEMO-SALES-CONVERSION' => '[DEMO] Konversi penjualan',
                'DEMO-SALES-FOLLOWUP' => '[DEMO] Tindak lanjut prospek',
            ],
        ],
        'ACC-AR' => [
            'template' => 'DEMO-TPL-AR',
            'kpis' => [
                'DEMO-AR-COLLECTION' => '[DEMO] Penyelesaian penagihan',
                'DEMO-AR-ACCURACY' => '[DEMO] Akurasi pencatatan piutang',
            ],
        ],
        'PROD-WOVEN' => [
            'template' => 'DEMO-TPL-WOVEN',
            'kpis' => [
                'DEMO-WOVEN-OUTPUT' => '[DEMO] Pencapaian output woven',
                'DEMO-WOVEN-QUALITY' => '[DEMO] Kesesuaian mutu woven',
            ],
        ],
    ];

    public function run(
        KpiDefinitionService $definitions,
        KpiTemplateService $templates,
        PeriodService $periods,
        TargetService $targets,
        ActualService $actuals,
        BusinessTime $time,
    ): void {
        if (! app()->environment('local')) {
            throw new RuntimeException('DevelopmentDemoSeeder may only run in the local environment.');
        }

        $existing = AssessmentPeriod::query()->where('code', self::PERIOD_CODE)->first();

        if ($existing !== null) {
            $this->command->info('[DEMO] Dataset operasional lokal sudah ada; tidak ada duplikasi.');

            return;
        }

        $author = $this->actor('dev.hr');
        $reviewer = $this->actor('dev.management');
        $today = $time->businessDate(CarbonImmutable::now());
        $startsOn = CarbonImmutable::createFromFormat('!Y-m-d', $today)?->addMonthNoOverflow()->startOfMonth();

        if ($startsOn === null) {
            throw new RuntimeException('Local demo business date is invalid.');
        }

        $endsOn = $startsOn->endOfMonth();
        $definitionVersions = [];

        foreach (self::TEAMS as $teamCode => $setup) {
            $unitId = $this->unitId($teamCode);
            $weights = [];

            foreach ($setup['kpis'] as $code => $name) {
                $draft = $definitions->createDraft(
                    $author,
                    $code,
                    $unitId,
                    $this->definitionContent($name, $today),
                    changeReason: '[DEMO] Fixture lokal sintetis',
                );
                $submitted = $definitions->submitForReview($author, $draft->id, $draft->lock_version);
                $published = $definitions->publish($reviewer, $submitted->id, $submitted->lock_version);
                $definitionVersions[$code] = $published;
                $weights[$published->id] = '50';
            }

            $template = $templates->createTemplate(
                $author,
                $setup['template'],
                "[DEMO] Scorecard {$teamCode}",
                $unitId,
                'employee',
                null,
                'Fixture lokal sintetis; bukan template bisnis resmi.',
            );
            $template = $templates->updateDraftHeader(
                $author,
                $template->id,
                $template->lock_version,
                'Fixture lokal sintetis; bukan template bisnis resmi.',
                self::SIGNOFF,
                $today,
            );

            foreach ($weights as $definitionVersionId => $weight) {
                $templates->addItem(
                    $author,
                    $template->id,
                    $template->fresh()->lock_version,
                    $definitionVersionId,
                    $weight,
                    '[DEMO]',
                    true,
                    null,
                );
            }

            $submitted = $templates->submitForReview($author, $template->id, $template->fresh()->lock_version);
            $templates->publish($reviewer, $submitted->id, $submitted->lock_version);
        }

        $period = $periods->createDraft($author, new PeriodSetup(
            code: self::PERIOD_CODE,
            name: '[DEMO] Pilot KPI Lokal',
            type: 'monthly',
            startsOn: $startsOn->format('Y-m-d'),
            endsOn: $endsOn->format('Y-m-d'),
            unitId: $this->unitId('BAL'),
            referenceDate: $startsOn->format('Y-m-d'),
            notes: 'Data sintetis untuk preview lokal; bukan UAT atau data bisnis resmi.',
            deadlines: [],
        ));
        $period = $periods->open($author, $period->id, $period->lock_version);

        $periodTargets = PeriodTarget::query()
            ->where('assessment_period_id', $period->id)
            ->with('definitionVersion')
            ->orderBy('id')
            ->get();
        /** @var list<TargetEntry> $targetEntries */
        $targetEntries = $periodTargets->map(fn (PeriodTarget $target): TargetEntry => new TargetEntry(
            reference: $target->public_id,
            targetId: $target->id,
            value: '100',
            unit: (string) $target->definitionVersion->unit,
            sourceNote: '[DEMO] Target sintetis',
        ))->values()->all();

        $scope = new UnitScope(true, [], false);
        $targets->saveDrafts($author, $period->id, $scope, $targetEntries);
        $targets->submit($author, $period->id, $scope);
        $targets->approve($reviewer, $period->id, $scope);
        $period = $periods->startProgress($author, $period->id, $period->fresh()->lock_version);

        /** @var list<ActualEntry> $actualEntries */
        $actualEntries = $periodTargets->values()->map(fn (PeriodTarget $target, int $index): ActualEntry => new ActualEntry(
            reference: $target->public_id,
            targetId: $target->id,
            windowStart: $startsOn->format('Y-m-d'),
            windowEnd: $endsOn->format('Y-m-d'),
            value: (string) (88 + ($index % 5) * 3),
            unit: (string) $target->definitionVersion->unit,
            note: '[DEMO] Aktual sintetis',
            sourceSystem: 'local-demo-seeder',
            sourceReference: self::PERIOD_CODE.'-'.$target->public_id,
        ))->values()->all();

        $actuals->saveDrafts($author, $period->id, ActualAccess::everything(), $actualEntries);
        $actuals->submit($author, $period->id, ActualAccess::everything());
        $actuals->verify($reviewer, $period->id, ActualAccess::everything());

        $this->command->info(sprintf(
            '[DEMO] Dibuat: %d KPI published, 3 template, 1 periode, %d target, dan %d aktual sintetis.',
            count($definitionVersions),
            $periodTargets->count(),
            $actualEntries === [] ? 0 : count($actualEntries),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function definitionContent(string $name, string $effectiveFrom): array
    {
        return [
            'name' => $name,
            'purpose' => 'Preview alur sistem KPI menggunakan data sintetis.',
            'description' => 'KPI demonstrasi lokal. Jangan digunakan sebagai keputusan atau hasil bisnis.',
            'process_owner' => '[DEMO] Pemilik proses sintetis',
            'data_owner' => '[DEMO] Pemilik data sintetis',
            'evaluation_subject' => 'employee',
            'unit' => 'percent',
            'value_type' => 'percentage',
            'direction' => 'higher_is_better',
            'frequency' => 'monthly',
            'aggregation' => 'average',
            'formula_description' => '[DEMO] Aktual dibagi target dikali 100.',
            'numerator_definition' => '[DEMO] Nilai aktual sintetis.',
            'denominator_definition' => '[DEMO] Target sintetis.',
            'score_strategy' => 'ratio_to_target',
            'score_strategy_version' => 1,
            'score_parameters' => [
                'missing_data_rule' => 'block_until_provided',
                'rounding_mode' => 'half_up',
                'rounding_scale' => 2,
                'score_floor' => '0',
                'score_cap' => '120',
                'zero_target_rule' => 'not_applicable',
            ],
            'data_source' => '[DEMO] Seeder lokal',
            'input_method' => 'manual',
            'evidence_requirement' => 'none',
            'evidence_description' => '',
            'exception_policy' => '[DEMO] Missing data ditahan; N/A memerlukan catatan.',
            'target_rule' => '[DEMO] Target tetap untuk preview lokal.',
            'approval_chain' => '[DEMO] HR mengajukan, Management mereview.',
            'signoff_reference' => self::SIGNOFF,
            'effective_from' => $effectiveFrom,
        ];
    }

    private function actor(string $localPart): Actor
    {
        $user = User::query()->where('email', "{$localPart}@".DevelopmentIdentitySeeder::EMAIL_DOMAIN)->firstOrFail();

        return new Actor($user->id, Actor::system('seeder', self::class)->audit);
    }

    private function unitId(string $code): int
    {
        $id = OrganizationUnit::query()->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("Organization unit [{$code}] is missing.");
        }

        return (int) $id;
    }
}
