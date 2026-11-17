<?php

namespace Tests\Feature\Domains\People\Services;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\StatusPtk;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Services\PelanggaranAturanKepegawaian;
use App\Domains\People\Services\PtkService;
use Closure;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Leaving (mutasi, pensiun, berhenti) and coming back: the status, the exit
 * date and note, the account that follows the status, and the log.
 */
class PtkStatusTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_leaving_stores_the_status_date_and_note_and_deactivates_a_guru_only_account(): void
    {
        $operator = User::factory()->operator()->create();
        $ptk = Ptk::factory()->create();

        $this->service()->ubahStatus($operator, $ptk, $this->keluar(['status' => 'pensiun', 'keterangan_keluar' => '  Pensiun   usia ']));

        $ptk = $ptk->fresh();
        $this->assertSame(StatusPtk::Pensiun, $ptk->status);
        $this->assertSame('2026-01-31', $ptk->tanggal_keluar->toDateString());
        $this->assertSame('Pensiun usia', $ptk->keterangan_keluar);
        $this->assertFalse($ptk->user->status_aktif);

        $log = Activity::query()->where('event', 'ptk_status_diubah')->sole();
        $this->assertSame('kepegawaian', $log->log_name);
        $this->assertSame('Status guru/staf diubah', $log->description);
        $this->assertTrue($log->causer->is($operator));
        $this->assertTrue($log->subject->is($ptk));
        $this->assertSame(
            ['status' => 'pensiun', 'tanggal_keluar' => '2026-01-31', 'keterangan_keluar' => 'Pensiun usia'],
            $this->sorted($log->attribute_changes['attributes'], ['status', 'tanggal_keluar', 'keterangan_keluar']),
        );
        $this->assertSame(['status' => 'aktif'], $log->attribute_changes['old']);

        $akunLog = Activity::query()->where('event', 'dinonaktifkan')->sole();
        $this->assertSame('pengguna', $akunLog->log_name);
        $this->assertTrue($akunLog->subject->is($ptk->user));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function adminAccounts(): array
    {
        return [
            'super_admin' => ['superAdmin'],
            'operator' => ['operator'],
        ];
    }

    #[DataProvider('adminAccounts')]
    public function test_the_account_of_a_super_admin_or_operator_stays_active(string $state): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $ptk = Ptk::factory()->for(User::factory()->{$state}())->create();

        $this->service()->ubahStatus($superAdmin, $ptk, $this->keluar(['status' => 'mutasi']));

        $this->assertSame(StatusPtk::Mutasi, $ptk->fresh()->status);
        $this->assertTrue($ptk->user->fresh()->status_aktif);
        $this->assertSame(0, Activity::query()->where('event', 'dinonaktifkan')->count());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidExits(): array
    {
        return [
            'status aktif' => [['status' => 'aktif'], 'status'],
            'status kosong' => [['status' => null], 'status'],
            'status tidak dikenal' => [['status' => 'cuti'], 'status'],
            'tanggal kosong' => [['tanggal_keluar' => null], 'tanggal_keluar'],
            'tanggal bukan Y-m-d' => [['tanggal_keluar' => '31/01/2026'], 'tanggal_keluar'],
            'tanggal besok' => [['tanggal_keluar' => '2026-11-18'], 'tanggal_keluar'],
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
        $this->travelTo('2026-11-17 10:00:00');
        $ptk = Ptk::factory()->create();

        $this->assertValidationError($field, fn () => $this->service()->ubahStatus(User::factory()->operator()->create(), $ptk, $this->keluar($override)));

        $this->assertSame(StatusPtk::Aktif, $ptk->fresh()->status);
        $this->assertTrue($ptk->user->fresh()->status_aktif);
    }

    public function test_the_leaving_date_may_be_today(): void
    {
        $this->travelTo('2026-11-17 23:59:00');
        $ptk = Ptk::factory()->create();

        $this->service()->ubahStatus(User::factory()->operator()->create(), $ptk, $this->keluar(['tanggal_keluar' => '2026-11-17']));

        $this->assertSame('2026-11-17', $ptk->fresh()->tanggal_keluar->toDateString());
    }

    public function test_a_staff_member_who_already_left_cannot_leave_again(): void
    {
        $operator = User::factory()->operator()->create();
        $ptk = Ptk::factory()->create();
        $this->service()->ubahStatus($operator, $ptk, $this->keluar(['status' => 'mutasi']));

        $this->assertRejected(
            PelanggaranAturanKepegawaian::sudahTidakAktif()->getMessage(),
            fn () => $this->service()->ubahStatus($operator, $ptk->fresh(), $this->keluar(['status' => 'berhenti'])),
        );

        $this->assertSame(StatusPtk::Mutasi, $ptk->fresh()->status);
    }

    public function test_coming_back_clears_the_exit_and_reactivates_the_account_the_status_change_deactivated(): void
    {
        $operator = User::factory()->operator()->create();
        $ptk = Ptk::factory()->create();
        $this->service()->ubahStatus($operator, $ptk, $this->keluar(['status' => 'berhenti']));

        $this->service()->aktifkanKembali($operator, $ptk->fresh());

        $ptk = $ptk->fresh();
        $this->assertSame(StatusPtk::Aktif, $ptk->status);
        $this->assertNull($ptk->tanggal_keluar);
        $this->assertNull($ptk->keterangan_keluar);
        $this->assertNull($ptk->log_penonaktifan_akun_id);
        $this->assertTrue($ptk->user->status_aktif);

        $log = Activity::query()->where('event', 'ptk_diaktifkan_kembali')->sole();
        $this->assertSame('Guru/staf diaktifkan kembali', $log->description);
        $this->assertTrue($log->causer->is($operator));
        $this->assertSame(['status' => 'aktif', 'tanggal_keluar' => null, 'keterangan_keluar' => null], $this->sorted($log->attribute_changes['attributes'], ['status', 'tanggal_keluar', 'keterangan_keluar']));
        $this->assertSame(['status' => 'berhenti', 'tanggal_keluar' => '2026-01-31', 'keterangan_keluar' => 'Pindah tugas'], $this->sorted($log->attribute_changes['old'], ['status', 'tanggal_keluar', 'keterangan_keluar']));
        $this->assertSame(1, Activity::query()->where('event', 'diaktifkan')->count());
    }

    public function test_an_account_deactivated_in_kelola_pengguna_before_leaving_stays_inactive_when_coming_back(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $ptk = Ptk::factory()->create();
        app(UserService::class)->deactivate($superAdmin, $ptk->user);

        $this->service()->ubahStatus($superAdmin, $ptk->fresh(), $this->keluar());
        $this->assertNull($ptk->fresh()->log_penonaktifan_akun_id);

        $this->service()->aktifkanKembali($superAdmin, $ptk->fresh());

        $this->assertSame(StatusPtk::Aktif, $ptk->fresh()->status);
        $this->assertFalse($ptk->user->fresh()->status_aktif);
        $this->assertSame(0, Activity::query()->where('event', 'diaktifkan')->count());
    }

    public function test_an_account_deactivated_again_in_kelola_pengguna_after_leaving_stays_inactive_when_coming_back(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $ptk = Ptk::factory()->create();
        $this->service()->ubahStatus($superAdmin, $ptk, $this->keluar());
        app(UserService::class)->activate($superAdmin, $ptk->user->fresh());
        app(UserService::class)->deactivate($superAdmin, $ptk->user->fresh());

        $this->service()->aktifkanKembali($superAdmin, $ptk->fresh());

        $this->assertSame(StatusPtk::Aktif, $ptk->fresh()->status);
        $this->assertFalse($ptk->user->fresh()->status_aktif);
    }

    public function test_an_account_reactivated_in_kelola_pengguna_meanwhile_stays_active_without_another_log(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $ptk = Ptk::factory()->create();
        $this->service()->ubahStatus($superAdmin, $ptk, $this->keluar());
        app(UserService::class)->activate($superAdmin, $ptk->user->fresh());

        $this->service()->aktifkanKembali($superAdmin, $ptk->fresh());

        $this->assertTrue($ptk->user->fresh()->status_aktif);
        $this->assertSame(1, Activity::query()->where('event', 'diaktifkan')->count());
    }

    public function test_an_active_staff_member_cannot_be_reactivated(): void
    {
        $ptk = Ptk::factory()->create();

        $this->assertRejected(
            PelanggaranAturanKepegawaian::sudahAktif()->getMessage(),
            fn () => $this->service()->aktifkanKembali(User::factory()->operator()->create(), $ptk),
        );

        $this->assertSame(0, Activity::query()->where('event', 'ptk_diaktifkan_kembali')->count());
    }

    public function test_a_failure_while_deactivating_the_account_leaves_the_status_unchanged(): void
    {
        $ptk = Ptk::factory()->create();
        User::updating(function (): void {
            throw new RuntimeException('Gagal menyimpan akun.');
        });

        try {
            $this->service()->ubahStatus(User::factory()->operator()->create(), $ptk, $this->keluar());
            $this->fail('The failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gagal menyimpan akun.', $exception->getMessage());
        }

        $this->assertSame(StatusPtk::Aktif, $ptk->fresh()->status);
        $this->assertSame(0, Activity::query()->where('event', 'ptk_status_diubah')->count());
    }

    #[DataProvider('adminAccounts')]
    public function test_operator_may_not_change_the_status_of_a_super_admin_or_another_operator(string $state): void
    {
        $ptk = Ptk::factory()->for(User::factory()->{$state}())->create();
        $operator = User::factory()->operator()->create();

        $this->assertRejected(PelanggaranAturanPengguna::tidakBolehMengelola()->getMessage(), fn () => $this->service()->ubahStatus($operator, $ptk, $this->keluar()));

        $this->assertSame(StatusPtk::Aktif, $ptk->fresh()->status);
    }

    #[DataProvider('adminAccounts')]
    public function test_operator_may_not_reactivate_a_super_admin_or_another_operator(string $state): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $ptk = Ptk::factory()->for(User::factory()->{$state}())->create();
        $this->service()->ubahStatus($superAdmin, $ptk, $this->keluar());
        $operator = User::factory()->operator()->create();

        $this->assertRejected(PelanggaranAturanPengguna::tidakBolehMengelola()->getMessage(), fn () => $this->service()->aktifkanKembali($operator, $ptk->fresh()));

        $this->assertSame(StatusPtk::Mutasi, $ptk->fresh()->status);
    }

    private function service(): PtkService
    {
        return app(PtkService::class);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function keluar(array $override = []): array
    {
        return [
            'status' => 'mutasi',
            'tanggal_keluar' => '2026-01-31',
            'keterangan_keluar' => 'Pindah tugas',
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
        } catch (PelanggaranAturanKepegawaian|PelanggaranAturanPengguna $exception) {
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
