<?php

namespace Tests\Feature\Domains\People\Services;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\PenempatanRombel;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PenempatanRombelService;
use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\StatusKepegawaian;
use App\Domains\People\Services\ImportSiswaService;
use App\Domains\People\Services\PtkService;
use App\Domains\People\Services\SiswaService;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Domains\People\Services\Import\MembuatFileImport;
use Tests\TestCase;

/**
 * Which identity locks (KunciIdentitas) each write takes, before any other
 * lock, and that no write locks siswa, wali_murid or users whole. The
 * account writes that are not identity writes (status, password) take none,
 * so they never wait for an import.
 */
class UrutanKunciIdentitasTest extends TestCase
{
    use LazilyRefreshDatabase, MembuatFileImport, MemeriksaUrutanKunci;

    private User $operator;

    private User $superAdmin;

    private TahunAjaran $tahunAjaran;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        $this->operator = User::factory()->operator()->create();
        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->tahunAjaran = TahunAjaran::factory()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26', 'status_aktif' => true]);
    }

    public function test_student_writes(): void
    {
        $siswa = app(SiswaService::class);

        $this->assertIdentityLocks(['siswa', 'akun'], fn () => $siswa->create($this->operator, $this->dataSiswa()));
        $budi = Siswa::query()->where('nis', '20261001')->sole();
        $this->assertIdentityLocks(['siswa', 'akun'], fn () => $siswa->update($this->operator, $budi, $this->dataSiswa(['alamat' => 'Jl. Melati'])));
        $this->assertIdentityLocks(['siswa'], fn () => $siswa->ubahStatus($this->operator, $budi->fresh(), ['status' => 'keluar', 'tanggal_keluar' => '2026-06-20', 'keterangan_keluar' => 'Pindah sekolah']));
        $this->assertIdentityLocks(['siswa'], fn () => $siswa->aktifkanKembali($this->operator, $budi->fresh()));
        $this->assertSame(StatusSiswa::Aktif, $budi->fresh()?->status);
    }

    public function test_rombel_placements(): void
    {
        $penempatan = app(PenempatanRombelService::class);
        $budi = Siswa::factory()->create();
        $x1 = Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1]);
        $x2 = Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 2]);

        $this->assertIdentityLocks(['siswa'], fn () => $penempatan->tempatkan($this->operator, $budi, $x1));
        $this->assertIdentityLocks(['siswa'], fn () => $penempatan->pindahkan($this->operator, $budi->fresh(), $x2));
        $this->assertIdentityLocks(['siswa'], fn () => $penempatan->hapus($this->operator, PenempatanRombel::query()->where('siswa_id', $budi->id)->sole()));
    }

    public function test_account_identity_writes_take_the_account_lock(): void
    {
        $users = app(UserService::class);

        $this->assertIdentityLocks(['akun'], fn () => $users->create($this->superAdmin, ['name' => 'Budi Santoso', 'username' => 'budi', 'email' => null, 'password' => 'rahasia-baru-123', 'peran' => Peran::Operator->value]));
        $budi = User::query()->where('username', 'budi')->sole();
        $this->assertIdentityLocks(['akun'], fn () => $users->update($this->superAdmin, $budi, ['name' => 'Budi S', 'username' => 'budi', 'email' => null, 'peran' => Peran::Operator->value]));
    }

    public function test_account_status_and_password_take_no_identity_lock(): void
    {
        $users = app(UserService::class);
        $guru = User::factory()->operator()->create();

        $this->assertIdentityLocks([], fn () => $users->deactivate($this->superAdmin, $guru));
        $this->assertIdentityLocks([], fn () => $users->activate($this->superAdmin, $guru->fresh()));
        $this->assertIdentityLocks([], fn () => $users->resetPassword($this->superAdmin, $guru->fresh(), 'kata-sandi-baru-123'));
    }

    public function test_staff_writes_take_the_account_lock_first(): void
    {
        $ptk = app(PtkService::class);

        $this->assertIdentityLocks(['akun'], fn () => $ptk->create($this->operator, $this->dataPtk()));
        $hafida = Ptk::query()->sole();
        $this->assertIdentityLocks(['akun'], fn () => $ptk->update($this->operator, $hafida, [...$this->dataPtk(), 'username' => 'hafida', 'alamat' => 'Jl. Melati', 'nik' => null, 'hapus_nik' => false]));
    }

    public function test_an_import_takes_all_three_and_no_whole_table(): void
    {
        Storage::fake('local');
        Queue::fake();
        Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1]);
        $service = app(ImportSiswaService::class);
        $import = $service->mulai($this->operator, $this->tahunAjaran, $this->fileImport($this->tahunAjaran, [
            ['nis' => '20261001', 'nama' => 'Budi', 'jenis_kelamin' => 'L', 'rombel' => 'X-1', 'ayah_nama' => 'Ahmad'],
        ]), 'siswa.xlsx');

        $this->assertIdentityLocks(['siswa', 'wali_murid', 'akun'], fn () => $service->pratinjau($import));
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function dataSiswa(array $override = []): array
    {
        return [
            'name' => 'Budi', 'nis' => '20261001', 'nisn' => null, 'email' => null, 'nik' => null, 'jenis_kelamin' => JenisKelamin::LakiLaki->value,
            'tempat_lahir' => 'Kendari', 'tanggal_lahir' => '2011-05-01', 'agama' => 'Islam', 'alamat' => null, 'no_hp' => null, 'foto' => null,
            'tanggal_masuk' => null, 'asal_sekolah' => null,
            ...$override,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dataPtk(): array
    {
        return [
            'akun' => 'baru', 'user_id' => null, 'name' => 'Hafida', 'username' => 'hafida', 'email' => null, 'nik' => null,
            'jenis_kelamin' => 'perempuan', 'tempat_lahir' => 'Kendari', 'tanggal_lahir' => '1980-01-01', 'agama' => 'Islam',
            'alamat' => null, 'no_hp' => null, 'foto' => null, 'jenis_ptk' => 'guru',
            'status_kepegawaian_id' => StatusKepegawaian::query()->where('nama', 'PNS')->value('id'),
            'nip' => null, 'nuptk' => null, 'gelar_depan' => null, 'gelar_belakang' => null, 'kode' => null,
            'sertifikasi' => false, 'tahun_sertifikasi' => null,
        ];
    }
}
