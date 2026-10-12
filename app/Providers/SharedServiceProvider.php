<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Shared\Application\Audit\AuditPayloadSanitizer;
use App\Modules\Shared\Application\Errors\ExceptionMapper;
use App\Modules\Shared\Application\Outbox\OutboxConsumerRegistry;
use App\Modules\Shared\Application\Storage\ObjectMetadataStore;
use App\Modules\Shared\Application\Time\BusinessTime;
use App\Modules\Shared\Infrastructure\Storage\EloquentObjectMetadataStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditPayloadSanitizer::class);
        $this->app->singleton(ExceptionMapper::class);
        $this->app->singleton(
            BusinessTime::class,
            fn (): BusinessTime => new BusinessTime(
                Config::string('app.business_timezone', 'Asia/Jakarta'),
            ),
        );
        $this->app->singleton(
            OutboxConsumerRegistry::class,
            fn (Application $app): OutboxConsumerRegistry => new OutboxConsumerRegistry(
                $app->tagged('outbox.consumers'),
            ),
        );
        $this->app->bind(ObjectMetadataStore::class, EloquentObjectMetadataStore::class);
    }
}
