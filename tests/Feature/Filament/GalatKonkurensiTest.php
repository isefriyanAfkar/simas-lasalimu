<?php

namespace Tests\Feature\Filament;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\WaliMurid;
use App\Domains\People\Services\WaliMuridService;
use App\Filament\Resources\WaliMurids\Pages\ListWaliMurids;
use App\Filament\Support\GalatKonkurensi;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A deadlock or lock wait timeout that is still there after the retries
 * becomes one notification for every Filament action, instead of a server
 * error. Any other database error stays an error.
 */
class GalatKonkurensiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->operator()->create());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function concurrencyErrors(): array
    {
        return [
            '1213 deadlock' => [1213, 'Deadlock found when trying to get lock; try restarting transaction'],
            '1205 lock wait timeout' => [1205, 'Lock wait timeout exceeded; try restarting transaction'],
        ];
    }

    #[DataProvider('concurrencyErrors')]
    public function test_a_concurrency_error_in_an_action_becomes_a_notification(int $kode, string $pesan): void
    {
        $wali = WaliMurid::factory()->create();
        $this->partialMock(WaliMuridService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('ubahStatusMeninggal')
            ->andThrow(self::galat($kode, $pesan)));

        Livewire::test(ListWaliMurids::class)
            ->callAction(TestAction::make('tandaiMeninggal')->table($wali))
            ->assertNotified(GalatKonkurensi::PESAN);

        $this->assertFalse($wali->fresh()->meninggal);
    }

    public function test_a_deadlock_rethrown_from_a_nested_transaction_is_recognized(): void
    {
        $wali = WaliMurid::factory()->create();
        $asli = self::galat(1213, 'Deadlock found when trying to get lock; try restarting transaction');
        $this->partialMock(WaliMuridService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('ubahStatusMeninggal')
            ->andThrow(new DeadlockException($asli->getMessage(), (int) $asli->getCode(), $asli)));

        Livewire::test(ListWaliMurids::class)
            ->callAction(TestAction::make('tandaiMeninggal')->table($wali))
            ->assertNotified(GalatKonkurensi::PESAN);
    }

    public function test_any_other_database_error_is_not_hidden(): void
    {
        $wali = WaliMurid::factory()->create();
        $this->partialMock(WaliMuridService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('ubahStatusMeninggal')
            ->andThrow(self::galat(1062, "Duplicate entry '1' for key 'x'")));

        $this->expectException(QueryException::class);

        Livewire::test(ListWaliMurids::class)
            ->callAction(TestAction::make('tandaiMeninggal')->table($wali));
    }

    private static function galat(int $kode, string $pesan): QueryException
    {
        $pdo = new PDOException("SQLSTATE[HY000]: General error: {$kode} {$pesan}");
        $pdo->errorInfo = ['HY000', $kode, $pesan];

        return new QueryException('mariadb', 'update `wali_murid` set `meninggal` = ?', [1], $pdo);
    }
}
