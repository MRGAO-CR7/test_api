<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Audit\AuditLoggerInterface;
use App\Support\Audit\LogChannelAuditLogger;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Keep the binding stateless and process-shared. The logger reads
        // the current Request out of the container at write time, so a
        // single instance is safe across requests in long-lived workers.
        $this->app->singleton(AuditLoggerInterface::class, LogChannelAuditLogger::class);
    }

    public function boot(): void
    {
        //
    }
}
