<?php

namespace Tests\Feature\Domains\People\Services;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\HubunganWali;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\SiswaWali;
use App\Domains\People\Models\WaliMurid;
use App\Domains\People\Services\SiswaWaliService;
use App\Domains\People\Services\WaliMuridService;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Every path that touches siswa_wali takes its locks in one order: the
 * identity locks it needs (KunciIdentitas: siswa → wali_murid → akun), then
 * S (siswa) → W (wali_murid) → users → R (siswa_wali). Paths that start from
 * the parent (WaliMuridService) or the account (Kelola Pengguna) start
 * further along the same order. With one order, no two paths can wait on
 * each other in a cycle (MemeriksaUrutanKunci).
 */
class UrutanKunciWaliTest extends TestCase
{
    use LazilyRefreshDatabase, MemeriksaUrutanKunci;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        $this->operator = User::factory()->operator()->create();
    }

    public function test_adding_an_existing_parent(): void
    {
        $siswa = Siswa::factory()->create();
        $wali = WaliMurid::factory()->create();

        $this->assertLockOrder(['kunci:siswa', 'kunci:wali_murid', 'kunci:akun', 'siswa', 'wali_murid', 'users', 'siswa_wali'], fn () => $this->siswaWali()->tambahkan($this->operator, $siswa, [
            'wali' => 'ada',
            'wali_murid_id' => $wali->id,
            'hubungan' => 'ayah',
        ]));
    }

    public function test_adding_a_new_parent(): void
    {
        $siswa = Siswa::factory()->create();

        $this->assertLockOrder(['kunci:siswa', 'kunci:wali_murid', 'kunci:akun', 'siswa', 'siswa_wali'], fn () => $this->siswaWali()->tambahkan($this->operator, $siswa, [
            'wali' => 'baru',
            'hubungan' => 'ibu',
            'name' => 'Siti',
        ]));
    }

    public function test_changing_the_relation(): void
    {
        $siswaWali = $this->relasi(HubunganWali::Wali, utama: false);

        $this->assertLockOrder(['kunci:siswa', 'kunci:wali_murid', 'siswa', 'wali_murid', 'users', 'siswa_wali'], fn () => $this->siswaWali()->ubahHubungan($this->operator, $siswaWali, ['hubungan' => 'ayah']));
    }

    public function test_making_the_main_contact(): void
    {
        $siswaWali = $this->relasi(HubunganWali::Wali, utama: false);

        $this->assertLockOrder(['kunci:siswa', 'kunci:wali_murid', 'siswa', 'wali_murid', 'users', 'siswa_wali'], fn () => $this->siswaWali()->jadikanUtama($this->operator, $siswaWali));
    }

    public function test_letting_go(): void
    {
        $siswaWali = $this->relasi(HubunganWali::Wali, utama: false);

        $this->assertLockOrder(['kunci:siswa', 'kunci:wali_murid', 'siswa', 'wali_murid', 'users', 'siswa_wali'], fn () => $this->siswaWali()->lepas($this->operator, $siswaWali));
    }

    public function test_marking_as_died_and_back(): void
    {
        $wali = $this->relasi(HubunganWali::Wali, utama: false)->waliMurid;

        $this->assertLockOrder(['kunci:wali_murid', 'wali_murid', 'users', 'siswa_wali'], fn () => $this->waliMurid()->ubahStatusMeninggal($this->operator, $wali, true));
        $this->assertLockOrder(['kunci:wali_murid', 'wali_murid', 'users'], fn () => $this->waliMurid()->ubahStatusMeninggal($this->operator, $wali->fresh(), false));
    }

    public function test_changing_a_parent(): void
    {
        $wali = $this->relasi(HubunganWali::Ayah, utama: true)->waliMurid;

        $this->assertLockOrder(['kunci:wali_murid', 'kunci:akun', 'wali_murid', 'users', 'siswa_wali'], fn () => $this->waliMurid()->ubah($this->operator, $wali, [
            'name' => 'Ahmad Baru',
            'jenis_kelamin' => 'laki-laki',
        ]));
    }

    public function test_deleting_a_parent(): void
    {
        $siswaWali = $this->relasi(HubunganWali::Wali, utama: false);
        $wali = $siswaWali->waliMurid;
        $siswaWali->delete();

        $this->assertLockOrder(['kunci:wali_murid', 'kunci:akun', 'wali_murid', 'users', 'siswa_wali'], fn () => $this->waliMurid()->hapus($this->operator, $wali));
    }

    public function test_changing_a_parent_account_in_kelola_pengguna(): void
    {
        $user = $this->relasi(HubunganWali::Ayah, utama: true)->waliMurid->user;

        $this->assertLockOrder(['kunci:akun', 'siswa_wali'], function () use ($user): void {
            try {
                app(UserService::class)->update(User::factory()->superAdmin()->create(), $user, [
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => null,
                    'peran' => null,
                    'jenis_kelamin' => 'perempuan',
                ]);
            } catch (ValidationException) {
                // Refused: an ayah stays male. Only the locks matter here.
            }
        });
    }

    private function relasi(HubunganWali $hubungan, bool $utama): SiswaWali
    {
        $user = User::factory()->state(['name' => 'Ahmad', 'jenis_kelamin' => $hubungan === HubunganWali::Ibu ? 'perempuan' : 'laki-laki']);

        return SiswaWali::factory()
            ->for(WaliMurid::factory()->for($user))
            ->create(['hubungan' => $hubungan, 'utama' => $utama]);
    }

    private function siswaWali(): SiswaWaliService
    {
        return app(SiswaWaliService::class);
    }

    private function waliMurid(): WaliMuridService
    {
        return app(WaliMuridService::class);
    }
}
