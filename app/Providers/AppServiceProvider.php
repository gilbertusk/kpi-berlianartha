<?php

namespace App\Providers;

use App\Support\Operations\Heartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureOperations();
    }

    /**
     * Record a worker heartbeat on every queue worker loop so the worker
     * container healthcheck can detect a stalled process.
     */
    protected function configureOperations(): void
    {
        Event::listen(Looping::class, function (): void {
            // Looping is dispatched outside the worker's own error handling, so a
            // transient cache failure must be reported instead of stopping the worker.
            try {
                $this->app->make(Heartbeat::class)->beat('worker');
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
