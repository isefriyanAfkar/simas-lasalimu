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
use Closure;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Every path that touches siswa_wali takes its locks in one order:
 * S (siswa) → W (wali_murid) → users → R (siswa_wali). Paths that start from
 * the parent (WaliMuridService) or the account (Kelola Pengguna) start
 * further along the same order. With one order, no two paths can wait on
 * each other in a cycle.
 *
 * Two real concurrent transactions cannot be staged in this suite (one PHP
 * thread), so these tests check the SQL: the locking reads, in the order
 * they run. Locking a table again that the transaction already holds (the
 * account while it is saved) takes no new step and is allowed.
 */
class UrutanKunciWaliTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * "users" is one account row; "users(semua)" is every user row
     * (UserService::lockUsers(), taken when an account is validated). Both
     * belong to the users step, but locking every row is a new lock even
     * when one row is already held, so it must come before R too.
     *
     * @var array<string, int>
     */
    private const array URUTAN = ['siswa' => 0, 'wali_murid' => 1, 'users' => 2, 'users(semua)' => 2, 'siswa_wali' => 3];

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

        $this->assertLockOrder(['siswa', 'wali_murid', 'users', 'siswa_wali'], fn () => $this->siswaWali()->tambahkan($this->operator, $siswa, [
            'wali' => 'ada',
            'wali_murid_id' => $wali->id,
            'hubungan' => 'ayah',
        ]));
    }

    public function test_adding_a_new_parent(): void
    {
        $siswa = Siswa::factory()->create();

        $this->assertLockOrder(['siswa', 'users(semua)', 'siswa_wali'], fn () => $this->siswaWali()->tambahkan($this->operator, $siswa, [
            'wali' => 'baru',
            'hubungan' => 'ibu',
            'name' => 'Siti',
        ]));
    }

    public function test_changing_the_relation(): void
    {
        $siswaWali = $this->relasi(HubunganWali::Wali, utama: false);

        $this->assertLockOrder(['siswa', 'wali_murid', 'users', 'siswa_wali'], fn () => $this->siswaWali()->ubahHubungan($this->operator, $siswaWali, ['hubungan' => 'ayah']));
    }

    public function test_making_the_main_contact(): void
    {
        $siswaWali = $this->relasi(HubunganWali::Wali, utama: false);

        $this->assertLockOrder(['siswa', 'wali_murid', 'users', 'siswa_wali'], fn () => $this->siswaWali()->jadikanUtama($this->operator, $siswaWali));
    }

    public function test_letting_go(): void
    {
        $siswaWali = $this->relasi(HubunganWali::Wali, utama: false);

        $this->assertLockOrder(['siswa', 'wali_murid', 'users', 'siswa_wali'], fn () => $this->siswaWali()->lepas($this->operator, $siswaWali));
    }

    public function test_marking_as_died_and_back(): void
    {
        $wali = $this->relasi(HubunganWali::Wali, utama: false)->waliMurid;

        $this->assertLockOrder(['wali_murid', 'users', 'siswa_wali'], fn () => $this->waliMurid()->ubahStatusMeninggal($this->operator, $wali, true));
        $this->assertLockOrder(['wali_murid', 'users'], fn () => $this->waliMurid()->ubahStatusMeninggal($this->operator, $wali->fresh(), false));
    }

    public function test_changing_a_parent(): void
    {
        $wali = $this->relasi(HubunganWali::Ayah, utama: true)->waliMurid;

        $this->assertLockOrder(['wali_murid', 'users', 'users(semua)', 'siswa_wali'], fn () => $this->waliMurid()->ubah($this->operator, $wali, [
            'name' => 'Ahmad Baru',
            'jenis_kelamin' => 'laki-laki',
        ]));
    }

    public function test_deleting_a_parent(): void
    {
        $siswaWali = $this->relasi(HubunganWali::Wali, utama: false);
        $wali = $siswaWali->waliMurid;
        $siswaWali->delete();

        $this->assertLockOrder(['wali_murid', 'users', 'siswa_wali'], fn () => $this->waliMurid()->hapus($this->operator, $wali));
    }

    public function test_changing_a_parent_account_in_kelola_pengguna(): void
    {
        $user = $this->relasi(HubunganWali::Ayah, utama: true)->waliMurid->user;

        $this->assertLockOrder(['users(semua)', 'siswa_wali'], function () use ($user): void {
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

    /**
     * Run $operation and check its locking reads: the first lock on each of
     * siswa, wali_murid, users and siswa_wali comes in $expected order, and
     * no table earlier in the order is locked for the first time after a
     * later one.
     *
     * @param  list<string>  $expected
     */
    private function assertLockOrder(array $expected, Closure $operation): void
    {
        /** @var list<string> $tabel */
        $tabel = [];
        $merekam = true;
        DB::listen(function (QueryExecuted $query) use (&$tabel, &$merekam): void {
            if ($merekam && preg_match('/(for update|lock in share mode)\s*$/i', $query->sql) === 1 && preg_match('/\bfrom `(\w+)`/', $query->sql, $cocok) === 1) {
                $tabel[] = $cocok[1] === 'users' && ! str_contains($query->sql, ' where ') ? 'users(semua)' : $cocok[1];
            }
        });

        $operation();
        $merekam = false;

        $pertama = [];
        $tertinggi = -1;

        foreach ($tabel as $nama) {
            $sudahDipegang = in_array($nama, $pertama, true)
                || ($nama === 'users' && in_array('users(semua)', $pertama, true));

            if (! array_key_exists($nama, self::URUTAN) || $sudahDipegang) {
                continue;
            }

            $this->assertGreaterThanOrEqual($tertinggi, self::URUTAN[$nama], "{$nama} is locked after a later step. Locks: ".implode(' → ', $tabel));
            $tertinggi = self::URUTAN[$nama];
            $pertama[] = $nama;
        }

        $this->assertSame($expected, $pertama, 'Locks: '.implode(' → ', $tabel));
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
