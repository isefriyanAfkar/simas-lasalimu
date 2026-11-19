<?php

namespace Tests\Feature\Domains\Academic\Services;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\PenempatanRombel;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PenempatanRombelService;
use App\Domains\Academic\Services\RombelService;
use App\Domains\Foundation\Enums\Jenjang;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Settings\PengaturanSekolah;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Services\SiswaService;
use Closure;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Every count that enforces a limit (the capacity of a rombel, the members
 * that pin its school year) is a locking read. Under REPEATABLE READ a plain
 * read uses the transaction's snapshot and can miss a placement committed by
 * another transaction while this one waited for the rombel lock; a locking
 * read always sees the latest committed rows.
 *
 * Two real concurrent transactions cannot be staged in this suite (one PHP
 * thread, and the test data lives in an uncommitted transaction a second
 * connection cannot see), so these tests check the SQL itself: every count
 * of penempatan_rombel carries a lock, and the active-student count joins
 * siswa instead of a subquery (a lock does not reach rows read in a nested
 * subquery).
 */
class HitunganKapasitasTerkunciTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $operator;

    private TahunAjaran $tahunAjaran;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        $settings = app(PengaturanSekolah::class);
        $settings->jenjang = Jenjang::Sma;
        $settings->save();
        $this->operator = User::factory()->operator()->create();
        $this->tahunAjaran = TahunAjaran::factory()->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26', 'status_aktif' => true]);
    }

    public function test_placing_counts_the_active_students_with_a_lock(): void
    {
        $rombel = $this->rombel(1, kapasitas: 30);

        $this->assertLockedCounts(fn () => app(PenempatanRombelService::class)->tempatkan($this->operator, $this->siswa(), $rombel));
    }

    public function test_moving_counts_the_active_students_with_a_lock(): void
    {
        $siswa = $this->siswa();
        PenempatanRombel::factory()->for($siswa)->for($this->rombel(1))->create();
        $tujuan = $this->rombel(2, kapasitas: 30);

        $this->assertLockedCounts(fn () => app(PenempatanRombelService::class)->pindahkan($this->operator, $siswa, $tujuan));
    }

    public function test_coming_back_counts_the_active_students_with_a_lock(): void
    {
        $siswa = $this->siswa(StatusSiswa::Keluar);
        PenempatanRombel::factory()->for($siswa)->for($this->rombel(1, kapasitas: 30))->create();

        $this->assertLockedCounts(fn () => app(SiswaService::class)->aktifkanKembali($this->operator, $siswa));
    }

    public function test_lowering_the_capacity_and_changing_the_school_year_count_with_a_lock(): void
    {
        $rombel = $this->rombel(1, kapasitas: 30);
        PenempatanRombel::factory()->for($this->siswa())->for($rombel)->create();
        $lain = TahunAjaran::factory()->create(['nama' => '2027/2028', 'tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2028-06-24']);

        $this->assertLockedCounts(function () use ($rombel, $lain): void {
            try {
                app(RombelService::class)->update($rombel, [
                    'tahun_ajaran_id' => $lain->id,
                    'tingkat' => Tingkat::X->value,
                    'nomor' => 1,
                    'kapasitas' => 20,
                ]);
            } catch (ValidationException) {
                // Refused because of the member: only the reads matter here.
            }
        }, minimum: 2);
    }

    private function assertLockedCounts(Closure $operation, int $minimum = 1): void
    {
        /** @var list<string> $hitungan */
        $hitungan = [];
        DB::listen(function (QueryExecuted $query) use (&$hitungan): void {
            if (preg_match('/count\(\*\)/i', $query->sql) && str_contains($query->sql, 'penempatan_rombel')) {
                $hitungan[] = $query->sql;
            }
        });

        $operation();

        $this->assertGreaterThanOrEqual($minimum, count($hitungan), 'No count of penempatan_rombel was run.');

        foreach ($hitungan as $sql) {
            $this->assertMatchesRegularExpression('/(for update|lock in share mode)\s*$/i', $sql, "Plain read: {$sql}");

            if (str_contains($sql, '`siswa`')) {
                $this->assertDoesNotMatchRegularExpression('/exists\s*\(/i', $sql, "The status is read in a subquery the lock does not reach: {$sql}");
            }
        }
    }

    private function rombel(int $nomor, ?int $kapasitas = null): Rombel
    {
        return Rombel::factory()->for($this->tahunAjaran)->create(['tingkat' => Tingkat::X, 'nomor' => $nomor, 'kapasitas' => $kapasitas]);
    }

    private function siswa(StatusSiswa $status = StatusSiswa::Aktif): Siswa
    {
        return Siswa::factory()->create(['status' => $status]);
    }
}
