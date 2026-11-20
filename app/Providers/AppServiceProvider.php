<?php

namespace App\Providers;

use App\Domains\Foundation\Policies\ActivityPolicy;
use App\Domains\Foundation\Services\KunciSekaliPerTransaksi;
use App\Filament\Support\GalatKonkurensi;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not a singleton: a queue worker forgets it before every
        // job, so no lock mark travels from one job to the next.
        $this->app->scoped(KunciSekaliPerTransaksi::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Activity::class, ActivityPolicy::class);
        GalatKonkurensi::daftarkan();
        KunciSekaliPerTransaksi::daftarkan();
    }
}
