<?php

namespace App\Providers;

use App\Domains\Foundation\Policies\ActivityPolicy;
use App\Filament\Support\GalatKonkurensi;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

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
        Gate::policy(Activity::class, ActivityPolicy::class);
        GalatKonkurensi::daftarkan();
        $this->folderSementaraPerProsesTest();
    }

    /**
     * php artisan test --parallel: every process gets its own temporary
     * folder (simas.folder_sementara with _test_<token>), so a test that
     * looks at the files in it never sees those of another process. Set for
     * every test, because every test boots a fresh application; the folder
     * is made by ImportSiswaService::fileSementaraBaru() when first needed
     * and stays after the run (storage/app/private is not in git).
     *
     * Only setUpTestCase, which runs inside the test process: setUpProcess
     * and tearDownProcess run in the parent process, and not at all when
     * laravel/pao replaces Laravel's parallel runner (in an agent session).
     */
    private function folderSementaraPerProsesTest(): void
    {
        ParallelTesting::setUpTestCase(function (int $token): void {
            config(['simas.folder_sementara' => rtrim((string) config('simas.folder_sementara'), '\\/').'_test_'.$token]);
        });
    }
}
