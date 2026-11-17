<?php

namespace Tests\Feature\Domains\Academic\Services;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\PenempatanRombel;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PelanggaranAturanAkademik;
use App\Domains\Academic\Services\PenempatanRombelService;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use Closure;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Placing students in a rombel: one rombel per school year, capacity counted
 * on active students only, only active students, only the active or a later
 * school year, placing and moving kept apart, and deleting a mistaken
 * placement.
 */
class PenempatanRombelServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $operator;

    private TahunAjaran $tahunAjaran;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        $this->operator = User::factory()->operator()->create();
        $this->tahunAjaran = $this->tahunAjaran('2026/2027', aktif: true);
    }

    public function test_placing_stores_the_rombels_school_year_and_is_logged(): void
    {
        $siswa = $this->siswa('Budi');
        $rombel = $this->rombel(1);

        $penempatan = $this->service()->tempatkan($this->operator, $siswa, $rombel);

        $this->assertSame($siswa->id, $penempatan->siswa_id);
        $this->assertSame($rombel->id, $penempatan->rombel_id);
        $this->assertSame($this->tahunAjaran->id, $penempatan->tahun_ajaran_id);

        $log = Activity::query()->where('event', 'siswa_ditempatkan')->sole();
        $this->assertSame('akademik', $log->log_name);
        $this->assertSame('Siswa ditempatkan di rombel', $log->description);
        $this->assertTrue($log->causer->is($this->operator));
        $this->assertTrue($log->subject->is($siswa));
        $this->assertSame(['rombel' => 'X-1 2026/2027'], $log->attribute_changes['attributes']);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function rombelInTheSameYear(): array
    {
        return [
            'rombel yang sama' => [1],
            'rombel lain' => [2],
        ];
    }

    #[DataProvider('rombelInTheSameYear')]
    public function test_placing_a_student_who_already_has_a_rombel_that_year_is_rejected_and_moves_nothing(int $nomorTujuan): void
    {
        $siswa = $this->siswa('Budi');
        $x1 = $this->rombel(1);
        $tujuan = $nomorTujuan === 1 ? $x1 : $this->rombel(2);
        $this->service()->tempatkan($this->operator, $siswa, $x1);

        $this->assertRejected('Budi sudah di X-1.', fn () => $this->service()->tempatkan($this->operator, $siswa, $tujuan));

        $this->assertSame([$x1->id], PenempatanRombel::query()->pluck('rombel_id')->all());
        $this->assertSame(1, Activity::query()->where('event', 'siswa_ditempatkan')->count());
        $this->assertSame(0, Activity::query()->where('event', 'siswa_dipindah_rombel')->count());
    }

    public function test_a_student_may_have_one_rombel_in_each_school_year(): void
    {
        $siswa = $this->siswa('Budi');
        $tahunDepan = $this->tahunAjaran('2027/2028');

        $this->service()->tempatkan($this->operator, $siswa, $this->rombel(1));
        $this->service()->tempatkan($this->operator, $siswa, $this->rombel(1, tahunAjaran: $tahunDepan, tingkat: Tingkat::XI));

        $this->assertSame(2, PenempatanRombel::query()->where('siswa_id', $siswa->id)->count());
    }

    public function test_moving_replaces_the_placement_of_that_school_year_and_is_logged(): void
    {
        $siswa = $this->siswa('Budi');
        $x1 = $this->rombel(1);
        $x2 = $this->rombel(2);
        $awal = $this->service()->tempatkan($this->operator, $siswa, $x1);

        $pindah = $this->service()->pindahkan($this->operator, $siswa, $x2);

        $this->assertSame($awal->id, $pindah->id);
        $this->assertSame([$x2->id], PenempatanRombel::query()->pluck('rombel_id')->all());

        $log = Activity::query()->where('event', 'siswa_dipindah_rombel')->sole();
        $this->assertSame('Siswa dipindah rombel', $log->description);
        $this->assertTrue($log->subject->is($siswa));
        $this->assertSame(['rombel' => 'X-2 2026/2027'], $log->attribute_changes['attributes']);
        $this->assertSame(['rombel' => 'X-1 2026/2027'], $log->attribute_changes['old']);
    }

    public function test_moving_needs_a_rombel_in_that_school_year_first(): void
    {
        $siswa = $this->siswa('Budi');
        $this->service()->tempatkan($this->operator, $siswa, $this->rombel(1, tahunAjaran: $this->tahunAjaran('2027/2028'), tingkat: Tingkat::XI));

        $this->assertRejected(
            'Budi belum punya rombel di tahun ajaran 2026/2027. Tempatkan dulu, baru bisa dipindah.',
            fn () => $this->service()->pindahkan($this->operator, $siswa, $this->rombel(2)),
        );

        $this->assertSame(0, PenempatanRombel::query()->where('tahun_ajaran_id', $this->tahunAjaran->id)->count());
    }

    public function test_moving_into_the_same_rombel_is_rejected(): void
    {
        $siswa = $this->siswa('Budi');
        $x1 = $this->rombel(1);
        $this->service()->tempatkan($this->operator, $siswa, $x1);

        $this->assertRejected('Budi sudah di X-1.', fn () => $this->service()->pindahkan($this->operator, $siswa, $x1));
        $this->assertSame(0, Activity::query()->where('event', 'siswa_dipindah_rombel')->count());
    }

    /**
     * @return array<string, array{StatusSiswa}>
     */
    public static function inactiveStatuses(): array
    {
        return [
            'lulus' => [StatusSiswa::Lulus],
            'mutasi keluar' => [StatusSiswa::MutasiKeluar],
            'keluar' => [StatusSiswa::Keluar],
        ];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_a_student_who_is_not_active_cannot_be_placed_or_moved(StatusSiswa $status): void
    {
        $siswa = $this->siswa('Budi');
        $x1 = $this->rombel(1);
        $this->service()->tempatkan($this->operator, $siswa, $x1);
        $siswa->update(['status' => $status]);
        $lain = $this->siswa('Ani', $status);

        $this->assertRejected('Ani tidak aktif dan tidak bisa ditempatkan.', fn () => $this->service()->tempatkan($this->operator, $lain, $x1));
        $this->assertRejected('Budi tidak aktif dan tidak bisa ditempatkan.', fn () => $this->service()->pindahkan($this->operator, $siswa->fresh(), $this->rombel(2)));

        $this->assertSame([$x1->id], PenempatanRombel::query()->pluck('rombel_id')->all());
    }

    public function test_a_full_rombel_rejects_a_placement_and_names_its_capacity(): void
    {
        $rombel = $this->rombel(1, kapasitas: 2);
        $this->service()->tempatkan($this->operator, $this->siswa('Ani'), $rombel);

        $this->service()->tempatkan($this->operator, $this->siswa('Budi'), $rombel);

        $this->assertRejected(
            'Rombel X-1 sudah penuh (2 siswa aktif). Naikkan kapasitasnya dulu.',
            fn () => $this->service()->tempatkan($this->operator, $this->siswa('Citra'), $rombel),
        );
        $this->assertSame(2, PenempatanRombel::query()->count());
    }

    public function test_a_full_rombel_rejects_a_move_into_it(): void
    {
        $penuh = $this->rombel(1, kapasitas: 1);
        $this->service()->tempatkan($this->operator, $this->siswa('Ani'), $penuh);
        $budi = $this->siswa('Budi');
        $x2 = $this->rombel(2);
        $this->service()->tempatkan($this->operator, $budi, $x2);

        $this->assertRejected(
            'Rombel X-1 sudah penuh (1 siswa aktif). Naikkan kapasitasnya dulu.',
            fn () => $this->service()->pindahkan($this->operator, $budi, $penuh),
        );
        $this->assertSame($x2->id, PenempatanRombel::query()->where('siswa_id', $budi->id)->value('rombel_id'));
    }

    public function test_an_empty_capacity_means_no_limit(): void
    {
        $rombel = $this->rombel(1, kapasitas: null);

        foreach (['Ani', 'Budi', 'Citra', 'Dodi'] as $nama) {
            $this->service()->tempatkan($this->operator, $this->siswa($nama), $rombel);
        }

        $this->assertSame(4, PenempatanRombel::query()->count());
    }

    #[DataProvider('inactiveStatuses')]
    public function test_students_who_left_do_not_count_against_the_capacity(StatusSiswa $status): void
    {
        $rombel = $this->rombel(1, kapasitas: 1);
        $ani = $this->siswa('Ani');
        $this->service()->tempatkan($this->operator, $ani, $rombel);
        $ani->update(['status' => $status]);

        $this->service()->tempatkan($this->operator, $this->siswa('Budi'), $rombel);

        $this->assertSame(2, PenempatanRombel::query()->where('rombel_id', $rombel->id)->count());
    }

    public function test_a_rombel_of_an_earlier_school_year_is_rejected(): void
    {
        $lama = $this->tahunAjaran('2025/2026');
        $siswa = $this->siswa('Budi');
        $rombelLama = $this->rombel(1, tahunAjaran: $lama);

        $this->assertRejected(
            'Tahun ajaran 2025/2026 sudah lewat. Pilih rombel di tahun ajaran aktif atau sesudahnya.',
            fn () => $this->service()->tempatkan($this->operator, $siswa, $rombelLama),
        );

        PenempatanRombel::factory()->for($siswa)->for($this->rombel(2, tahunAjaran: $lama))->create();

        $this->assertRejected(
            'Tahun ajaran 2025/2026 sudah lewat. Pilih rombel di tahun ajaran aktif atau sesudahnya.',
            fn () => $this->service()->pindahkan($this->operator, $siswa, $rombelLama),
        );
        $this->assertSame(1, PenempatanRombel::query()->count());
    }

    public function test_without_an_active_school_year_nothing_can_be_placed(): void
    {
        $this->tahunAjaran->forceFill(['status_aktif' => false])->save();

        $this->assertRejected(
            PelanggaranAturanAkademik::belumAdaTahunAjaranAktif()->getMessage(),
            fn () => $this->service()->tempatkan($this->operator, $this->siswa('Budi'), $this->rombel(1)),
        );
    }

    public function test_the_database_refuses_a_school_year_that_differs_from_the_rombels(): void
    {
        $rombel = $this->rombel(1);

        $this->expectException(QueryException::class);

        DB::table('penempatan_rombel')->insert([
            'siswa_id' => $this->siswa('Budi')->id,
            'rombel_id' => $rombel->id,
            'tahun_ajaran_id' => $this->tahunAjaran('2027/2028')->id,
        ]);
    }

    public function test_the_database_refuses_a_second_rombel_in_the_same_school_year(): void
    {
        $siswa = $this->siswa('Budi');
        PenempatanRombel::factory()->for($siswa)->for($this->rombel(1))->create();

        $this->expectException(QueryException::class);

        PenempatanRombel::factory()->for($siswa)->for($this->rombel(2))->create();
    }

    public function test_placing_several_students_keeps_the_successes_and_reports_each_failure(): void
    {
        $rombel = $this->rombel(1, kapasitas: 3);
        $x2 = $this->rombel(2);
        $ani = $this->siswa('Ani');
        $budi = $this->siswa('Budi');
        $citra = $this->siswa('Citra', StatusSiswa::Lulus);
        $dodi = $this->siswa('Dodi');
        $eka = $this->siswa('Eka');
        $fajar = $this->siswa('Fajar');
        $this->service()->tempatkan($this->operator, $budi, $x2);

        $hasil = $this->service()->tempatkanBanyak($this->operator, [$ani, $budi, $citra, $dodi, $eka, $fajar], $rombel);

        $this->assertSame([$ani->id, $dodi->id, $eka->id], $hasil['berhasil']);
        $this->assertSame([
            $budi->id => 'Budi sudah di X-2.',
            $citra->id => 'Citra tidak aktif dan tidak bisa ditempatkan.',
            $fajar->id => 'Rombel X-1 sudah penuh (3 siswa aktif). Naikkan kapasitasnya dulu.',
        ], $hasil['gagal']);

        $this->assertEqualsCanonicalizing([$ani->id, $dodi->id, $eka->id], PenempatanRombel::query()->where('rombel_id', $rombel->id)->pluck('siswa_id')->all());
        $this->assertSame($x2->id, PenempatanRombel::query()->where('siswa_id', $budi->id)->value('rombel_id'));
        $this->assertSame(0, Activity::query()->where('event', 'siswa_dipindah_rombel')->count());
    }

    public function test_deleting_the_placement_of_an_active_student_is_logged(): void
    {
        $siswa = $this->siswa('Budi');
        $penempatan = $this->service()->tempatkan($this->operator, $siswa, $this->rombel(1));

        $this->service()->hapus($this->operator, $penempatan);

        $this->assertSame(0, PenempatanRombel::query()->count());
        $log = Activity::query()->where('event', 'penempatan_rombel_dihapus')->sole();
        $this->assertSame('Penempatan rombel dihapus', $log->description);
        $this->assertTrue($log->subject->is($siswa));
        $this->assertSame(['rombel' => 'X-1 2026/2027'], $log->attribute_changes['old']);
        $this->assertSame(['rombel' => null], $log->attribute_changes['attributes']);
    }

    #[DataProvider('inactiveStatuses')]
    public function test_the_placement_of_a_student_who_left_is_history_and_cannot_be_deleted(StatusSiswa $status): void
    {
        $siswa = $this->siswa('Budi');
        $penempatan = $this->service()->tempatkan($this->operator, $siswa, $this->rombel(1));
        $siswa->update(['status' => $status]);

        $this->assertRejected(
            'Budi sudah tidak aktif. Penempatannya menjadi riwayat dan tidak bisa dihapus.',
            fn () => $this->service()->hapus($this->operator, $penempatan),
        );

        $this->assertSame(1, PenempatanRombel::query()->count());
    }

    public function test_a_placement_of_an_earlier_school_year_cannot_be_deleted(): void
    {
        $siswa = $this->siswa('Budi');
        $penempatan = PenempatanRombel::factory()->for($siswa)->for($this->rombel(1, tahunAjaran: $this->tahunAjaran('2025/2026')))->create();

        $this->assertRejected(
            'Tahun ajaran 2025/2026 sudah lewat. Pilih rombel di tahun ajaran aktif atau sesudahnya.',
            fn () => $this->service()->hapus($this->operator, $penempatan),
        );

        $this->assertSame(1, PenempatanRombel::query()->count());
    }

    private function service(): PenempatanRombelService
    {
        return app(PenempatanRombelService::class);
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

    private function rombel(int $nomor, ?int $kapasitas = null, ?TahunAjaran $tahunAjaran = null, Tingkat $tingkat = Tingkat::X): Rombel
    {
        return Rombel::factory()->for($tahunAjaran ?? $this->tahunAjaran)->create([
            'tingkat' => $tingkat,
            'nomor' => $nomor,
            'kapasitas' => $kapasitas,
        ]);
    }

    private function siswa(string $nama, StatusSiswa $status = StatusSiswa::Aktif): Siswa
    {
        return Siswa::factory()->for(User::factory()->state(['name' => $nama]))->create(['status' => $status]);
    }

    private function assertRejected(string $message, Closure $operation): void
    {
        try {
            $operation();
        } catch (PelanggaranAturanAkademik $exception) {
            $this->assertSame($message, $exception->getMessage());

            return;
        }

        $this->fail('Expected the operation to be rejected.');
    }
}
