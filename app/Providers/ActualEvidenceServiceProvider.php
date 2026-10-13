<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\ActualEvidence\Application\ActualPeriodAccess;
use App\Modules\ActualEvidence\Application\ActualReadiness;
use App\Modules\ActualEvidence\Application\EvidenceService;
use App\Modules\ActualEvidence\Application\MalwareScanner;
use App\Modules\ActualEvidence\Domain\EvidenceRules;
use App\Modules\ActualEvidence\Infrastructure\Scanning\LocalUnscannedScanner;
use App\Modules\ActualEvidence\Infrastructure\Scanning\UnavailableScanner;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

final class ActualEvidenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EvidenceRules::class, fn (): EvidenceRules => new EvidenceRules(
            Config::integer('evidence.max_bytes'),
            Config::integer('evidence.max_files_per_actual'),
        ));

        $this->app->bind(MalwareScanner::class, function (Application $app): MalwareScanner {
            $driver = Config::string('evidence.scanner', 'none');

            // Accepting unscanned files is a development convenience only.
            if ($driver === 'local-unscanned' && $app->environment(['local', 'testing'])) {
                return new LocalUnscannedScanner;
            }

            if ($driver !== 'none') {
                // Fail closed: an unknown or disallowed setting quarantines
                // every file instead of breaking or weakening evidence access.
                Log::warning('Evidence scanner setting is not available in this environment; files stay quarantined.', ['scanner' => $driver]);
            }

            return new UnavailableScanner;
        });

        $this->app->tag([ActualReadiness::class], PeriodAssignmentServiceProvider::LIFECYCLE_PARTICIPANTS);
        $this->app->when(EvidenceService::class)->needs('$disk')->giveConfig('evidence.disk');
        $this->app->when(EvidenceService::class)->needs('$linkTtlSeconds')->giveConfig('evidence.link_ttl_seconds');
    }

    public function boot(): void
    {
        Gate::define('actuals.view', fn (User $user): bool => $this->app->make(AccessControl::class)->allows($user, Capability::ActualView));

        foreach (['viewActuals' => Capability::ActualView, 'enterActuals' => Capability::ActualEnter, 'verifyActuals' => Capability::ActualVerify] as $ability => $capability) {
            Gate::define($ability, fn (User $user, AssessmentPeriod $period): bool => $this->app->make(ActualPeriodAccess::class)->concerns($user, $capability, $period));
        }
    }
}
