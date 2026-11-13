<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Booking\Events\WaitlistedStudentSkipped;
use App\Domain\Booking\Listeners\LogSkippedStudentNotification;
use App\Domain\Shared\Clock\Clock;
use App\Domain\Shared\Clock\SystemClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Event::listen(WaitlistedStudentSkipped::class, LogSkippedStudentNotification::class);
    }
}
