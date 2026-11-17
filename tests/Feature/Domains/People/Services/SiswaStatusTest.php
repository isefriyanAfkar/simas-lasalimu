<?php

namespace Tests\Feature\Domains\People\Services;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Services\PelanggaranAturanKesiswaan;
use App\Domains\People\Services\SiswaService;
use Closure;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * A student leaving (lulus, mutasi keluar, keluar) and coming back: the
 * status, the exit date and note, the account that follows the status, and
 * the log.
 */
class SiswaStatusTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_leaving_stores_the_status_date_and_note_and_deactivates_the_account(): void
    {
        $operator = User::factory()->operator()->create();
        $siswa = Siswa::factory()->create();

        $this->service()->ubahStatus($operator, $siswa, $this->keluar(['status' => 'mutasi_keluar', 'keterangan_keluar' => '  Pindah   ke Makassar ']));

        $siswa = $siswa->fresh();
        $this->assertSame(StatusSiswa::MutasiKeluar, $siswa->status);
        $this->assertSame('2026-06-20', $siswa->tanggal_keluar->toDateString());
        $this->assertSame('Pindah ke Makassar', $siswa->keterangan_keluar);
        $this->assertFalse($siswa->user->status_aktif);
        $this->assertNotNull($siswa->log_penonaktifan_akun_id);

        $log = Activity::query()->where('event', 'siswa_status_diubah')->sole();
        $this->assertSame('kesiswaan', $log->log_name);
        $this->assertSame('Status siswa diubah', $log->description);
        $this->assertTrue($log->causer->is($operator));
        $this->assertTrue($log->subject->is($siswa));
        $this->assertSame(
            ['status' => 'mutasi_keluar', 'tanggal_keluar' => '2026-06-20', 'keterangan_keluar' => 'Pindah ke Makassar'],
            $this->sorted($log->attribute_changes['attributes'], ['status', 'tanggal_keluar', 'keterangan_keluar']),
        );
        $this->assertSame(['status' => 'aktif'], $log->attribute_changes['old']);

        $akunLog = Activity::query()->where('event', 'dinonaktifkan')->sole();
        $this->assertSame('pengguna', $akunLog->log_name);
        $this->assertTrue($akunLog->subject->is($siswa->user));
        $this->assertSame($akunLog->id, $siswa->log_penonaktifan_akun_id);
    }

    /**
     * @return array<string, array{string, StatusSiswa}>
     */
    public static function leavingStatuses(): array
    {
        return [
            'lulus' => ['lulus', StatusSiswa::Lulus],
            'mutasi keluar' => ['mutasi_keluar', StatusSiswa::MutasiKeluar],
            'keluar' => ['keluar', StatusSiswa::Keluar],
        ];
    }

    #[DataProvider('leavingStatuses')]
    public function test_every_leaving_status_is_accepted(string $value, StatusSiswa $expected): void
    {
        $siswa = Siswa::factory()->create();

        $this->service()->ubahStatus(User::factory()->operator()->create(), $siswa, $this->keluar(['status' => $value]));

        $this->assertSame($expected, $siswa->fresh()->status);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidExits(): array
    {
        return [
            'status aktif' => [['status' => 'aktif'], 'status'],
            'status kosong' => [['status' => null], 'status'],
            'status tidak dikenal' => [['status' => 'pensiun'], 'status'],
            'tanggal kosong' => [['tanggal_keluar' => null], 'tanggal_keluar'],
            'tanggal bukan Y-m-d' => [['tanggal_keluar' => '20/06/2026'], 'tanggal_keluar'],
            'tanggal besok' => [['tanggal_keluar' => '2026-11-19'], 'tanggal_keluar'],
            'keterangan kosong' => [['keterangan_keluar' => '   '], 'keterangan_keluar'],
            'keterangan lebih dari 500' => [['keterangan_keluar' => str_repeat('a', 501)], 'keterangan_keluar'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidExits')]
    public function test_leaving_needs_a_leaving_status_a_date_up_to_today_and_a_note(array $override, string $field): void
    {
        $this->travelTo('2026-11-18 10:00:00');
        $siswa = Siswa::factory()->create();

        $this->assertValidationError($field, fn () => $this->service()->ubahStatus(User::factory()->operator()->create(), $siswa, $this->keluar($override)));

        $this->assertSame(StatusSiswa::Aktif, $siswa->fresh()->status);
        $this->assertTrue($siswa->user->fresh()->status_aktif);
    }

    public function test_the_leaving_date_may_be_today(): void
    {
        $this->travelTo('2026-11-18 23:59:00');
        $siswa = Siswa::factory()->create();

        $this->service()->ubahStatus(User::factory()->operator()->create(), $siswa, $this->keluar(['tanggal_keluar' => '2026-11-18']));

        $this->assertSame('2026-11-18', $siswa->fresh()->tanggal_keluar->toDateString());
    }

    public function test_a_student_who_already_left_cannot_leave_again(): void
    {
        $operator = User::factory()->operator()->create();
        $siswa = Siswa::factory()->create();
        $this->service()->ubahStatus($operator, $siswa, $this->keluar(['status' => 'lulus']));

        $this->assertRejected(
            PelanggaranAturanKesiswaan::sudahTidakAktif()->getMessage(),
            fn () => $this->service()->ubahStatus($operator, $siswa->fresh(), $this->keluar(['status' => 'keluar'])),
        );

        $this->assertSame(StatusSiswa::Lulus, $siswa->fresh()->status);
    }

    public function test_coming_back_clears_the_exit_and_reactivates_the_account_the_status_change_deactivated(): void
    {
        $operator = User::factory()->operator()->create();
        $siswa = Siswa::factory()->create();
        $this->service()->ubahStatus($operator, $siswa, $this->keluar(['status' => 'keluar']));

        $this->service()->aktifkanKembali($operator, $siswa->fresh());

        $siswa = $siswa->fresh();
        $this->assertSame(StatusSiswa::Aktif, $siswa->status);
        $this->assertNull($siswa->tanggal_keluar);
        $this->assertNull($siswa->keterangan_keluar);
        $this->assertNull($siswa->log_penonaktifan_akun_id);
        $this->assertTrue($siswa->user->status_aktif);

        $log = Activity::query()->where('event', 'siswa_diaktifkan_kembali')->sole();
        $this->assertSame('kesiswaan', $log->log_name);
        $this->assertSame('Siswa diaktifkan kembali', $log->description);
        $this->assertTrue($log->causer->is($operator));
        $this->assertSame(['status' => 'aktif', 'tanggal_keluar' => null, 'keterangan_keluar' => null], $this->sorted($log->attribute_changes['attributes'], ['status', 'tanggal_keluar', 'keterangan_keluar']));
        $this->assertSame(['status' => 'keluar', 'tanggal_keluar' => '2026-06-20', 'keterangan_keluar' => 'Pindah sekolah'], $this->sorted($log->attribute_changes['old'], ['status', 'tanggal_keluar', 'keterangan_keluar']));
        $this->assertSame(1, Activity::query()->where('event', 'diaktifkan')->count());
    }

    public function test_an_account_deactivated_in_kelola_pengguna_before_leaving_stays_inactive_when_coming_back(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $siswa = Siswa::factory()->create();
        app(UserService::class)->deactivate($superAdmin, $siswa->user);

        $this->service()->ubahStatus($superAdmin, $siswa->fresh(), $this->keluar());
        $this->assertNull($siswa->fresh()->log_penonaktifan_akun_id);

        $this->service()->aktifkanKembali($superAdmin, $siswa->fresh());

        $this->assertSame(StatusSiswa::Aktif, $siswa->fresh()->status);
        $this->assertFalse($siswa->user->fresh()->status_aktif);
        $this->assertSame(0, Activity::query()->where('event', 'diaktifkan')->count());
    }

    public function test_an_account_deactivated_again_in_kelola_pengguna_after_leaving_stays_inactive_when_coming_back(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $siswa = Siswa::factory()->create();
        $this->service()->ubahStatus($superAdmin, $siswa, $this->keluar());
        app(UserService::class)->activate($superAdmin, $siswa->user->fresh());
        app(UserService::class)->deactivate($superAdmin, $siswa->user->fresh());

        $this->service()->aktifkanKembali($superAdmin, $siswa->fresh());

        $this->assertSame(StatusSiswa::Aktif, $siswa->fresh()->status);
        $this->assertFalse($siswa->user->fresh()->status_aktif);
    }

    public function test_an_active_student_cannot_be_reactivated(): void
    {
        $siswa = Siswa::factory()->create();

        $this->assertRejected(
            PelanggaranAturanKesiswaan::sudahAktif()->getMessage(),
            fn () => $this->service()->aktifkanKembali(User::factory()->operator()->create(), $siswa),
        );

        $this->assertSame(0, Activity::query()->where('event', 'siswa_diaktifkan_kembali')->count());
    }

    public function test_a_failure_while_deactivating_the_account_leaves_the_status_unchanged(): void
    {
        $siswa = Siswa::factory()->create();
        $operator = User::factory()->operator()->create();
        User::updating(function (): void {
            throw new RuntimeException('Gagal menyimpan akun.');
        });

        try {
            $this->service()->ubahStatus($operator, $siswa, $this->keluar());
            $this->fail('The failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gagal menyimpan akun.', $exception->getMessage());
        }

        $this->assertSame(StatusSiswa::Aktif, $siswa->fresh()->status);
        $this->assertSame(0, Activity::query()->where('event', 'siswa_status_diubah')->count());
    }

    private function service(): SiswaService
    {
        return app(SiswaService::class);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function keluar(array $override = []): array
    {
        return [
            'status' => 'keluar',
            'tanggal_keluar' => '2026-06-20',
            'keterangan_keluar' => 'Pindah sekolah',
            ...$override,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $order
     * @return array<string, mixed>
     */
    private function sorted(array $values, array $order): array
    {
        $sorted = [];

        foreach ($order as $key) {
            $sorted[$key] = array_key_exists($key, $values) ? $values[$key] : 'MISSING';
        }

        $this->assertCount(count($order), $values);

        return $sorted;
    }

    private function assertRejected(string $message, Closure $operation): void
    {
        try {
            $operation();
        } catch (PelanggaranAturanKesiswaan $exception) {
            $this->assertSame($message, $exception->getMessage());

            return;
        }

        $this->fail('Expected the operation to be rejected.');
    }

    private function assertValidationError(string $field, Closure $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors(), 'Errors: '.json_encode($exception->errors()));

            return;
        }

        $this->fail("Expected a validation error on [{$field}].");
    }
}
