<?php

namespace Tests\Feature\Domains\Academic\Services;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\PenempatanRombel;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PelanggaranAturanAkademik;
use App\Domains\Academic\Services\RombelService;
use App\Domains\Foundation\Enums\Jenjang;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Settings\PengaturanSekolah;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Services\SiswaService;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A rombel that holds students: it cannot be deleted, cannot move to another
 * school year, and its capacity cannot drop below its active students; a
 * student coming back needs room in the rombel again.
 */
class RombelBerisiSiswaTest extends TestCase
{
    use LazilyRefreshDatabase;

    private TahunAjaran $tahunAjaran;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        $settings = app(PengaturanSekolah::class);
        $settings->jenjang = Jenjang::Sma;
        $settings->save();
        $this->tahunAjaran = $this->tahunAjaran('2026/2027', aktif: true);
    }

    public function test_a_rombel_with_placements_cannot_be_deleted_also_when_the_students_left(): void
    {
        $rombel = $this->rombel(kapasitas: null);
        $this->tempatkan($rombel, 'Ani');
        $this->tempatkan($rombel, 'Budi', StatusSiswa::Lulus);

        try {
            app(RombelService::class)->delete($rombel);
            $this->fail('A rombel with students was deleted.');
        } catch (PelanggaranAturanAkademik $exception) {
            $this->assertSame('Rombel X-1 sudah dipakai oleh 2 siswa dan tidak bisa dihapus.', $exception->getMessage());
        }

        $this->assertNotNull($rombel->fresh());
    }

    public function test_an_empty_rombel_can_still_be_deleted(): void
    {
        $rombel = $this->rombel(kapasitas: null);

        app(RombelService::class)->delete($rombel);

        $this->assertNull($rombel->fresh());
    }

    public function test_the_school_year_of_a_rombel_with_placements_cannot_change(): void
    {
        $rombel = $this->rombel(kapasitas: null);
        $this->tempatkan($rombel, 'Ani', StatusSiswa::Keluar);
        $lain = $this->tahunAjaran('2027/2028');

        $this->assertValidationError(
            'tahun_ajaran_id',
            'Tahun ajaran rombel ini tidak bisa diubah karena sudah punya 1 siswa.',
            fn () => app(RombelService::class)->update($rombel, $this->data($rombel, ['tahun_ajaran_id' => $lain->id])),
        );

        $this->assertSame($this->tahunAjaran->id, $rombel->fresh()->tahun_ajaran_id);
    }

    public function test_the_school_year_of_an_empty_rombel_may_change(): void
    {
        $rombel = $this->rombel(kapasitas: null);
        $lain = $this->tahunAjaran('2027/2028');

        app(RombelService::class)->update($rombel, $this->data($rombel, ['tahun_ajaran_id' => $lain->id]));

        $this->assertSame($lain->id, $rombel->fresh()->tahun_ajaran_id);
    }

    public function test_the_database_refuses_to_move_a_rombel_with_placements_to_another_school_year(): void
    {
        $rombel = $this->rombel(kapasitas: null);
        $this->tempatkan($rombel, 'Ani');

        $this->expectException(QueryException::class);

        DB::table('rombel')->where('id', $rombel->id)->update(['tahun_ajaran_id' => $this->tahunAjaran('2027/2028')->id]);
    }

    public function test_capacity_cannot_drop_below_the_active_students_but_may_equal_them(): void
    {
        $rombel = $this->rombel(kapasitas: 5);
        $this->tempatkan($rombel, 'Ani');
        $this->tempatkan($rombel, 'Budi');
        $this->tempatkan($rombel, 'Citra');

        $this->assertValidationError(
            'kapasitas',
            'Kapasitas tidak boleh kurang dari jumlah siswa aktif di rombel ini (3).',
            fn () => app(RombelService::class)->update($rombel, $this->data($rombel, ['kapasitas' => 2])),
        );
        $this->assertSame(5, $rombel->fresh()->kapasitas);

        app(RombelService::class)->update($rombel, $this->data($rombel, ['kapasitas' => 3]));
        $this->assertSame(3, $rombel->fresh()->kapasitas);
    }

    public function test_students_who_left_do_not_hold_capacity(): void
    {
        $rombel = $this->rombel(kapasitas: 3);
        $this->tempatkan($rombel, 'Ani');
        $this->tempatkan($rombel, 'Budi', StatusSiswa::MutasiKeluar);
        $this->tempatkan($rombel, 'Citra', StatusSiswa::Lulus);

        app(RombelService::class)->update($rombel, $this->data($rombel, ['kapasitas' => 1]));

        $this->assertSame(1, $rombel->fresh()->kapasitas);
    }

    public function test_coming_back_is_refused_while_the_rombel_is_full(): void
    {
        $operator = User::factory()->operator()->create();
        $rombel = $this->rombel(kapasitas: 2);
        $budi = $this->tempatkan($rombel, 'Budi');
        app(SiswaService::class)->ubahStatus($operator, $budi, ['status' => 'keluar', 'tanggal_keluar' => '2026-09-01', 'keterangan_keluar' => 'Pindah']);
        $this->tempatkan($rombel, 'Ani');
        $this->tempatkan($rombel, 'Citra');

        try {
            app(SiswaService::class)->aktifkanKembali($operator, $budi->fresh());
            $this->fail('A student came back into a full rombel.');
        } catch (PelanggaranAturanAkademik $exception) {
            $this->assertSame('Rombel X-1 sudah penuh (2 siswa aktif). Naikkan kapasitasnya dulu.', $exception->getMessage());
        }

        $this->assertSame(StatusSiswa::Keluar, $budi->fresh()->status);
        $this->assertFalse($budi->user->fresh()->status_aktif);
    }

    public function test_coming_back_works_when_the_rombel_has_room(): void
    {
        $operator = User::factory()->operator()->create();
        $rombel = $this->rombel(kapasitas: 2);
        $budi = $this->tempatkan($rombel, 'Budi');
        app(SiswaService::class)->ubahStatus($operator, $budi, ['status' => 'keluar', 'tanggal_keluar' => '2026-09-01', 'keterangan_keluar' => 'Pindah']);
        $this->tempatkan($rombel, 'Ani');

        app(SiswaService::class)->aktifkanKembali($operator, $budi->fresh());

        $this->assertSame(StatusSiswa::Aktif, $budi->fresh()->status);
        $this->assertSame(2, PenempatanRombel::query()->where('rombel_id', $rombel->id)->count());
    }

    public function test_a_full_rombel_of_an_earlier_school_year_does_not_block_coming_back(): void
    {
        $operator = User::factory()->operator()->create();
        $rombelLama = $this->rombel(kapasitas: 1, tahunAjaran: $this->tahunAjaran('2025/2026'));
        $budi = $this->tempatkan($rombelLama, 'Budi', StatusSiswa::Keluar);
        $this->tempatkan($rombelLama, 'Ani');

        app(SiswaService::class)->aktifkanKembali($operator, $budi);

        $this->assertSame(StatusSiswa::Aktif, $budi->fresh()->status);
    }

    private function tahunAjaran(string $nama, bool $aktif = false): TahunAjaran
    {
        $tahun = (int) substr($nama, 0, 4);

        return TahunAjaran::factory()->create([
            'nama' => $nama,
            'tanggal_mulai' => "{$tahun}-07-13",
            'tanggal_selesai' => ($tahun + 1).'-06-26',
            'status_aktif' => $aktif,
        ]);
    }

    private function rombel(?int $kapasitas, ?TahunAjaran $tahunAjaran = null): Rombel
    {
        return Rombel::factory()->for($tahunAjaran ?? $this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => 1, 'kapasitas' => $kapasitas]);
    }

    /**
     * A placement written as a row, with the student's status set after.
     */
    private function tempatkan(Rombel $rombel, string $nama, StatusSiswa $status = StatusSiswa::Aktif): Siswa
    {
        $siswa = Siswa::factory()->for(User::factory()->state(['name' => $nama]))->create(['status' => $status]);
        PenempatanRombel::factory()->for($siswa)->for($rombel)->create();

        return $siswa;
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function data(Rombel $rombel, array $override): array
    {
        return [
            'tahun_ajaran_id' => $rombel->tahun_ajaran_id,
            'tingkat' => $rombel->tingkat->value,
            'nomor' => $rombel->nomor,
            'kapasitas' => $rombel->kapasitas,
            ...$override,
        ];
    }

    private function assertValidationError(string $field, string $message, callable $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertSame([$message], $exception->errors()[$field] ?? null, 'Errors: '.json_encode($exception->errors()));

            return;
        }

        $this->fail("Expected a validation error on [{$field}].");
    }
}
