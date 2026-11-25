<?php

namespace Tests\Feature\Providers;

use Illuminate\Support\Facades\ParallelTesting;
use Tests\TestCase;

/**
 * php artisan test --parallel gives every process its own temporary folder
 * (AppServiceProvider), so a test that looks at the files in it
 * (AntreanImportSiswaTest) never sees the files of another process.
 */
class FolderSementaraParalelTest extends TestCase
{
    public function test_every_parallel_process_has_its_own_temporary_folder(): void
    {
        $token = ParallelTesting::token();

        $this->assertSame(
            $token === false ? storage_path('app/private/tmp') : storage_path('app/private/tmp_test_'.$token),
            config('simas.folder_sementara'),
        );
    }
}
