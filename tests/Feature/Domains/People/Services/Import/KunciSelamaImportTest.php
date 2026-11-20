<?php

namespace Tests\Feature\Domains\People\Services\Import;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\HubunganWali;
use App\Domains\People\Enums\StatusImportSiswa;
use App\Domains\People\Models\ImportSiswa;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\SiswaWali;
use App\Domains\People\Models\WaliMurid;
use App\Domains\People\Services\ImportSiswaService;
use App\Domains\People\Services\SiswaWaliService;
use Closure;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Database\DatabaseTerkomit;
use Tests\TestCase;
use Throwable;

/**
 * What waits while an import is being saved, tried for real from a second
 * database session (DatabaseTerkomit) with a lock wait of one second, from
 * inside the import's transaction (once its identity locks are held):
 * - logging in with "Ingat saya", logging out, a password rehash and a
 *   status change of an account the import does not touch never wait, nor
 *   the login of a student whose row is in the file unchanged;
 * - an identity write (a username, a new parent) waits for the import;
 * - the login of an account the import changes waits too: its row stays
 *   locked until the import commits (the documented limit).
 *
 * The login runs the guard and user provider of the application, on the
 * second session; not an HTTP request (the manual test covers that).
 */
class KunciSelamaImportTest extends TestCase
{
    use DatabaseTerkomit, MembuatFileImport;

    private const string BERJALAN = 'berjalan';

    private const string MENUNGGU = 'menunggu';

    /**
     * @var array<string, string>
     */
    private array $hasil = [];

    public function test_only_identity_writes_and_the_changed_accounts_wait_for_an_import(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->seed(PeranDanIzinSeeder::class);
        $operator = User::factory()->operator()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $guru = User::factory()->operator()->create(['username' => 'guru', 'remember_token' => null]);
        $tahunAjaran = TahunAjaran::factory()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26', 'status_aktif' => true]);
        Rombel::factory()->for($tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1]);
        $budi = Siswa::factory()->for(User::factory()->state(['name' => 'Budi', 'jenis_kelamin' => JenisKelamin::LakiLaki, 'remember_token' => null]))->create(['nis' => '20261001']);
        $citra = Siswa::factory()->for(User::factory()->state(['name' => 'Citra', 'jenis_kelamin' => JenisKelamin::Perempuan]))->create(['nis' => '20261002']);
        $darto = WaliMurid::factory()->for(User::factory()->state(['name' => 'Darto', 'jenis_kelamin' => JenisKelamin::LakiLaki, 'no_hp' => '6281234567890', 'remember_token' => null]))->create();
        SiswaWali::factory()->create(['siswa_id' => $citra->id, 'wali_murid_id' => $darto->id, 'hubungan' => HubunganWali::Ayah, 'utama' => true]);

        $service = app(ImportSiswaService::class);
        $import = $service->pratinjau($service->mulai($operator, $tahunAjaran, $this->fileImport($tahunAjaran, [
            ['nis' => '20261002', 'ayah_nama' => 'Darto', 'ayah_no_hp' => '081299998888'],
            ['nis' => '20261001', 'nama' => 'Budi'],
            ['nis' => '20261003', 'nama' => 'Eko', 'jenis_kelamin' => 'L', 'rombel' => 'X-1'],
        ]), 'siswa.xlsx'));
        $this->assertSame(StatusImportSiswa::Siap, $import->status, (string) json_encode($import->galat));
        $kedua = $this->koneksiKedua();

        // Eko is created after Darto's account was changed, inside the
        // save's transaction.
        Siswa::created(function (Siswa $siswa) use ($kedua, $guru, $superAdmin, $budi, $darto, $citra): void {
            if ($siswa->nis !== '20261003' || $this->hasil !== []) {
                return;
            }

            $this->coba('masuk guru dengan ingat saya', $kedua, fn () => Auth::guard('web')->login(User::query()->findOrFail($guru->id), true));
            $this->coba('keluar guru', $kedua, fn () => Auth::guard('web')->logout());
            $this->coba('rehash kata sandi guru', $kedua, fn () => Auth::guard('web')->getProvider()->rehashPasswordIfRequired(User::query()->findOrFail($guru->id), ['password' => 'password'], true));
            $this->coba('nonaktifkan guru', $kedua, fn () => app(UserService::class)->deactivate(User::query()->findOrFail($superAdmin->id), User::query()->findOrFail($guru->id)));
            $this->coba('masuk siswa yang barisnya tidak berubah', $kedua, fn () => Auth::guard('web')->getProvider()->updateRememberToken(User::query()->findOrFail($budi->user_id), Str::random(60)));
            $this->coba('masuk wali yang diubah import', $kedua, fn () => Auth::guard('web')->getProvider()->updateRememberToken(User::query()->findOrFail($darto->user_id), Str::random(60)));
            $this->coba('ubah username guru', $kedua, function () use ($superAdmin, $guru): void {
                $akun = User::query()->findOrFail($guru->id);
                app(UserService::class)->update(User::query()->findOrFail($superAdmin->id), $akun, ['name' => $akun->name, 'username' => 'guru2', 'email' => $akun->email, 'peran' => 'operator']);
            });
            $this->coba('tambah wali baru', $kedua, fn () => app(SiswaWaliService::class)->tambahkan(User::query()->findOrFail($superAdmin->id), Siswa::query()->findOrFail($citra->id), [
                'wali' => 'baru', 'hubungan' => 'wali', 'name' => 'Paman Citra',
            ]));
        });

        $import = $service->simpan($import, $operator);

        $this->assertSame(StatusImportSiswa::Selesai, $import->status);
        $this->assertSame([
            'masuk guru dengan ingat saya' => self::BERJALAN,
            'keluar guru' => self::BERJALAN,
            'rehash kata sandi guru' => self::BERJALAN,
            'nonaktifkan guru' => self::BERJALAN,
            'masuk siswa yang barisnya tidak berubah' => self::BERJALAN,
            'masuk wali yang diubah import' => self::MENUNGGU,
            'ubah username guru' => self::MENUNGGU,
            'tambah wali baru' => self::MENUNGGU,
        ], $this->hasil);
        $this->assertFalse(User::query()->findOrFail($guru->id)->status_aktif);
        $this->assertSame('guru', User::query()->findOrFail($guru->id)->username);
        $this->assertSame(1, ImportSiswa::query()->count());
    }

    /**
     * An operator resets the password of a student in the file and
     * deactivates the account while the import runs, before the import
     * reaches that row: the import still saves (MariaDB 11.6.2+ with
     * innodb_snapshot_isolation ON would otherwise refuse the row with
     * "Record has changed since last read"), and the operator's changes stay.
     */
    public function test_an_account_changed_by_someone_else_during_the_import_is_still_saved_without_losing_that_change(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->seed(PeranDanIzinSeeder::class);
        $operator = User::factory()->operator()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $tahunAjaran = TahunAjaran::factory()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26', 'status_aktif' => true]);
        Rombel::factory()->for($tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1]);
        $fani = Siswa::factory()->for(User::factory()->state(['name' => 'Fani', 'jenis_kelamin' => JenisKelamin::Perempuan, 'alamat' => 'Jl. Lama']))->create(['nis' => '20261005']);

        $service = app(ImportSiswaService::class);
        $import = $service->pratinjau($service->mulai($operator, $tahunAjaran, $this->fileImport($tahunAjaran, [
            ['nis' => '20261003', 'nama' => 'Eko', 'jenis_kelamin' => 'L', 'rombel' => 'X-1'],
            ['nis' => '20261005', 'alamat' => 'Jl. Baru'],
        ]), 'siswa.xlsx'));
        $this->assertSame(StatusImportSiswa::Siap, $import->status, (string) json_encode($import->galat));
        $kedua = $this->koneksiKedua();
        DB::statement('SET SESSION innodb_snapshot_isolation = ON');

        // Eko comes first: the import's transaction has read Fani's row
        // already (the NIS lookup of the check run), and changes it after.
        Siswa::created(function (Siswa $siswa) use ($kedua, $superAdmin, $fani): void {
            if ($siswa->nis !== '20261003' || $this->hasil !== []) {
                return;
            }

            $this->coba('atur ulang kata sandi Fani', $kedua, fn () => app(UserService::class)->resetPassword(User::query()->findOrFail($superAdmin->id), User::query()->findOrFail($fani->user_id), 'kata-sandi-baru-123'));
            $this->coba('nonaktifkan Fani', $kedua, fn () => app(UserService::class)->deactivate(User::query()->findOrFail($superAdmin->id), User::query()->findOrFail($fani->user_id)));
        });

        $import = $service->simpan($import, $operator);

        $this->assertSame(['atur ulang kata sandi Fani' => self::BERJALAN, 'nonaktifkan Fani' => self::BERJALAN], $this->hasil);
        $this->assertSame(StatusImportSiswa::Selesai, $import->status, (string) $import->pesan);
        $akun = User::query()->findOrFail($fani->user_id);
        $this->assertSame('Jl. Baru', $akun->alamat);
        $this->assertFalse($akun->status_aktif);
        $this->assertTrue(Hash::check('kata-sandi-baru-123', $akun->password));
    }

    /**
     * Run $aksi on the second session, as the default connection, and note
     * whether it went through or ran into the lock wait timeout (1205).
     */
    private function coba(string $nama, string $koneksi, Closure $aksi): void
    {
        $semula = DB::getDefaultConnection();
        DB::setDefaultConnection($koneksi);

        try {
            $aksi();
            $this->hasil[$nama] = self::BERJALAN;
        } catch (Throwable $exception) {
            $this->hasil[$nama] = self::menungguKunci($exception) ? self::MENUNGGU : 'galat: '.$exception->getMessage();
        } finally {
            if (DB::connection($koneksi)->transactionLevel() > 0) {
                DB::connection($koneksi)->rollBack(0);
            }

            DB::setDefaultConnection($semula);
        }
    }

    private static function menungguKunci(Throwable $exception): bool
    {
        for ($galat = $exception; $galat !== null; $galat = $galat->getPrevious()) {
            if (str_contains($galat->getMessage(), '1205') || str_contains($galat->getMessage(), 'Lock wait timeout')) {
                return true;
            }
        }

        return false;
    }
}
