<?php

namespace Tests\Feature\Database;

use App\Domains\People\Models\StatusKepegawaian;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The migration that creates status_kepegawaian also fills it, so a server
 * has the usual statuses right after `php artisan migrate`.
 */
class StatusKepegawaianAwalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_migration_fills_the_five_active_statuses_in_order(): void
    {
        $this->assertSame(
            [
                ['nama' => 'PNS', 'urutan' => 1, 'status_aktif' => true],
                ['nama' => 'PPPK', 'urutan' => 2, 'status_aktif' => true],
                ['nama' => 'GTT', 'urutan' => 3, 'status_aktif' => true],
                ['nama' => 'PTT', 'urutan' => 4, 'status_aktif' => true],
                ['nama' => 'GTBPNS', 'urutan' => 5, 'status_aktif' => true],
            ],
            StatusKepegawaian::query()->urutanTampilan()->get()
                ->map(fn (StatusKepegawaian $status): array => $status->only(['nama', 'urutan', 'status_aktif']))
                ->all(),
        );
    }

    public function test_the_filled_rows_have_timestamps(): void
    {
        $this->assertSame(0, StatusKepegawaian::query()->whereNull('created_at')->orWhereNull('updated_at')->count());
    }
}
