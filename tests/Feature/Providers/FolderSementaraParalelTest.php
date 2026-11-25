<?php

namespace Tests\Feature\Providers;

use Illuminate\Support\Facades\ParallelTesting;
use Tests\TestCase;

/**
 * php artisan test --parallel gives every process its own temporary folder
 * (AppServiceProvider), so a test that looks at the files in it
 * (AntreanImportSiswaTest) never sees the files of another process.
 *
 * Laravel runs the parallel callbacks only when LARAVEL_PARALLEL_TESTING is
 * set (php artisan test --parallel) and a token exists; under
 * vendor/bin/paratest alone only the token exists, and the folder stays the
 * default one.
 */
class FolderSementaraParalelTest extends TestCase
{
    public function test_every_parallel_process_has_its_own_temporary_folder(): void
    {
        $token = ParallelTesting::token();
        $paralel = ! empty($_SERVER['LARAVEL_PARALLEL_TESTING']) && $token !== false;

        $this->assertSame(
            $paralel ? storage_path('app/private/tmp_test_'.$token) : storage_path('app/private/tmp'),
            config('simas.folder_sementara'),
        );
    }
}
