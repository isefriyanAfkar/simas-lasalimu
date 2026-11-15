<?php

namespace Tests\Feature\Domains\Academic\Services;

use App\Domains\Academic\Enums\JenisSemester;
use App\Domains\Academic\Models\Semester;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PelanggaranAturanAkademik;
use App\Domains\Academic\Services\TahunAjaranService;
use App\Domains\Foundation\Models\User;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TahunAjaranServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_creates_a_school_year_with_its_two_default_semesters(): void
    {
        $tahunAjaran = $this->create('2026/2027', '2026-07-13', '2027-06-26');

        $this->assertSame('2026/2027', $tahunAjaran->nama);
        $this->assertFalse($tahunAjaran->status_aktif);

        $semester = $tahunAjaran->semester()->orderBy('tanggal_mulai')->get();
        $this->assertCount(2, $semester);
        $this->assertSame(JenisSemester::Ganjil, $semester[0]->jenis);
        $this->assertSame('2026-07-13', $semester[0]->tanggal_mulai->toDateString());
        $this->assertSame('2026-12-31', $semester[0]->tanggal_selesai->toDateString());
        $this->assertSame(JenisSemester::Genap, $semester[1]->jenis);
        $this->assertSame('2027-01-01', $semester[1]->tanggal_mulai->toDateString());
        $this->assertSame('2027-06-26', $semester[1]->tanggal_selesai->toDateString());
        $this->assertFalse($semester[0]->status_aktif);
        $this->assertFalse($semester[1]->status_aktif);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return [
            'pakai tanda hubung' => ['2026-2027'],
            'dua digit' => ['26/27'],
            'tahun kedua bukan tahun pertama + 1' => ['2026/2028'],
            'tahun kedua sama' => ['2026/2026'],
            'terbalik' => ['2027/2026'],
            'ada spasi' => ['2026 / 2027'],
        ];
    }

    #[DataProvider('invalidNames')]
    public function test_rejects_an_invalid_name(string $nama): void
    {
        $this->assertValidationError('nama', fn () => $this->create($nama, '2026-07-13', '2027-06-26'));

        $this->assertSame(0, TahunAjaran::query()->count());
        $this->assertSame(0, Semester::query()->count());
    }

    public function test_rejects_a_duplicate_name(): void
    {
        $this->create('2026/2027', '2026-07-13', '2027-06-26');

        $this->assertValidationError('nama', fn () => $this->create('2026/2027', '2026-07-14', '2027-06-25'));

        $this->assertSame(1, TahunAjaran::query()->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endNotAfterStart(): array
    {
        return [
            'sama dengan tanggal mulai' => ['2026-07-13', '2026-07-13'],
            'sebelum tanggal mulai' => ['2026-07-13', '2026-07-12'],
        ];
    }

    #[DataProvider('endNotAfterStart')]
    public function test_rejects_an_end_date_that_is_not_after_the_start_date(string $mulai, string $selesai): void
    {
        $this->assertValidationError('tanggal_selesai', fn () => $this->create('2026/2027', $mulai, $selesai));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function datesOutsideTheNamedYears(): array
    {
        return [
            'mulai bukan di tahun pertama' => ['2027-01-04', '2027-06-26'],
            'selesai bukan di tahun kedua' => ['2026-07-13', '2026-12-20'],
        ];
    }

    #[DataProvider('datesOutsideTheNamedYears')]
    public function test_rejects_dates_that_do_not_start_in_the_first_and_end_in_the_second_year(string $mulai, string $selesai): void
    {
        $this->expectException(ValidationException::class);

        $this->create('2026/2027', $mulai, $selesai);
    }

    /**
     * Existing school year: 2026-07-13 .. 2027-06-26.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function overlappingRanges(): array
    {
        return [
            'menyentuh dari depan' => ['2025/2026', '2025-07-14', '2026-07-13'],
            'menyentuh dari belakang' => ['2027/2028', '2027-06-26', '2028-06-24'],
            'bertumpang di depan' => ['2025/2026', '2025-07-14', '2026-08-01'],
            'bertumpang di belakang' => ['2027/2028', '2027-06-01', '2028-06-24'],
        ];
    }

    #[DataProvider('overlappingRanges')]
    public function test_rejects_a_range_that_overlaps_another_school_year(string $nama, string $mulai, string $selesai): void
    {
        $this->create('2026/2027', '2026-07-13', '2027-06-26');

        $this->assertValidationError('tanggal_mulai', fn () => $this->create($nama, $mulai, $selesai));

        $this->assertSame(1, TahunAjaran::query()->count());
    }

    public function test_accepts_ranges_that_only_touch_the_neighbours(): void
    {
        $this->create('2026/2027', '2026-07-13', '2027-06-26');

        $this->create('2025/2026', '2025-07-14', '2026-07-12');
        $this->create('2027/2028', '2027-06-27', '2028-06-24');

        $this->assertSame(3, TahunAjaran::query()->count());
    }

    public function test_rejects_an_update_that_overlaps_another_school_year(): void
    {
        $this->create('2026/2027', '2026-07-13', '2027-06-26');
        $lain = $this->create('2027/2028', '2027-07-12', '2028-06-24');

        $this->assertValidationError('tanggal_mulai', fn () => $this->service()->update($lain, [
            'nama' => '2027/2028',
            'tanggal_mulai' => '2027-06-20',
            'tanggal_selesai' => '2028-06-24',
        ]));

        $this->assertSame('2027-07-12', $lain->fresh()->tanggal_mulai->toDateString());
    }

    public function test_an_update_may_keep_its_own_name_and_range(): void
    {
        $tahunAjaran = $this->create('2026/2027', '2026-07-13', '2027-06-26');

        $this->service()->update($tahunAjaran, [
            'nama' => '2026/2027',
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2027-06-26',
        ]);

        $this->assertSame('2026-07-06', $tahunAjaran->fresh()->tanggal_mulai->toDateString());
    }

    public function test_rejects_an_update_that_leaves_a_semester_outside_the_new_range(): void
    {
        $tahunAjaran = $this->create('2026/2027', '2026-07-13', '2027-06-26');

        $this->assertValidationError('tanggal_mulai', fn () => $this->service()->update($tahunAjaran, [
            'nama' => '2026/2027',
            'tanggal_mulai' => '2026-07-20',
            'tanggal_selesai' => '2027-06-26',
        ]));

        $this->assertSame('2026-07-13', $tahunAjaran->fresh()->tanggal_mulai->toDateString());
    }

    public function test_activating_deactivates_the_other_school_year_and_its_semesters(): void
    {
        $this->travelTo('2026-08-01');
        $lama = $this->create('2025/2026', '2025-07-14', '2026-06-27');
        $baru = $this->create('2026/2027', '2026-07-13', '2027-06-26');
        $this->service()->activate($this->superAdmin(), $lama);

        $this->service()->activate($this->superAdmin(), $baru);

        $this->assertFalse($lama->fresh()->status_aktif);
        $this->assertTrue($baru->fresh()->status_aktif);
        $this->assertSame(0, $lama->semester()->where('status_aktif', true)->count());
        $this->assertSame(1, TahunAjaran::query()->where('status_aktif', true)->count());
        $this->assertSame(1, Semester::query()->where('status_aktif', true)->count());
    }

    /**
     * @return array<string, array{string, JenisSemester}>
     */
    public static function todayAndExpectedSemester(): array
    {
        return [
            'hari ini di Ganjil' => ['2026-09-15', JenisSemester::Ganjil],
            'hari ini di Genap' => ['2027-03-10', JenisSemester::Genap],
            'hari ini di luar keduanya' => ['2028-01-05', JenisSemester::Ganjil],
        ];
    }

    #[DataProvider('todayAndExpectedSemester')]
    public function test_activating_also_activates_the_semester_for_today_or_else_ganjil(string $today, JenisSemester $expected): void
    {
        $tahunAjaran = $this->create('2026/2027', '2026-07-13', '2027-06-26');
        $this->travelTo($today);

        $this->service()->activate($this->superAdmin(), $tahunAjaran);

        $aktif = Semester::query()->where('status_aktif', true)->sole();
        $this->assertSame($expected, $aktif->jenis);
        $this->assertTrue($aktif->tahunAjaran->is($tahunAjaran));
    }

    public function test_activation_of_school_year_and_semester_is_logged_with_the_actor(): void
    {
        $tahunAjaran = $this->create('2026/2027', '2026-07-13', '2027-06-26');
        $superAdmin = $this->superAdmin();
        $this->travelTo('2026-09-15');

        $this->service()->activate($superAdmin, $tahunAjaran);

        $tahunAjaranLog = Activity::query()->where('event', 'tahun_ajaran_diaktifkan')->sole();
        $this->assertTrue($tahunAjaranLog->causer->is($superAdmin));
        $this->assertTrue($tahunAjaranLog->subject->is($tahunAjaran));
        $this->assertSame(['status_aktif' => true], $tahunAjaranLog->attribute_changes['attributes']);
        $this->assertSame(['status_aktif' => false], $tahunAjaranLog->attribute_changes['old']);

        $semesterLog = Activity::query()->where('event', 'semester_diaktifkan')->sole();
        $this->assertTrue($semesterLog->causer->is($superAdmin));
        $this->assertTrue($semesterLog->subject->is($tahunAjaran->semesterGanjil));
    }

    public function test_activating_the_already_active_school_year_changes_and_logs_nothing(): void
    {
        $tahunAjaran = $this->create('2026/2027', '2026-07-13', '2027-06-26');
        $this->travelTo('2026-09-15');
        $this->service()->activate($this->superAdmin(), $tahunAjaran);

        $this->service()->activate($this->superAdmin(), $tahunAjaran->fresh());

        $this->assertSame(1, Activity::query()->where('event', 'tahun_ajaran_diaktifkan')->count());
        $this->assertSame(1, Semester::query()->where('status_aktif', true)->count());
    }

    public function test_cannot_delete_the_active_school_year(): void
    {
        $tahunAjaran = $this->create('2026/2027', '2026-07-13', '2027-06-26');
        $this->service()->activate($this->superAdmin(), $tahunAjaran);

        try {
            $this->service()->delete($tahunAjaran->fresh());
            $this->fail('The active school year was deleted.');
        } catch (PelanggaranAturanAkademik $exception) {
            $this->assertSame(PelanggaranAturanAkademik::tahunAjaranAktifTidakBolehDihapus()->getMessage(), $exception->getMessage());
        }

        $this->assertSame(1, TahunAjaran::query()->count());
        $this->assertSame(2, Semester::query()->count());
    }

    public function test_deleting_an_inactive_school_year_also_deletes_its_semesters(): void
    {
        $tahunAjaran = $this->create('2026/2027', '2026-07-13', '2027-06-26');
        $lain = $this->create('2027/2028', '2027-07-12', '2028-06-24');

        $this->service()->delete($tahunAjaran);

        $this->assertNull(TahunAjaran::query()->find($tahunAjaran->id));
        $this->assertSame(0, Semester::query()->where('tahun_ajaran_id', $tahunAjaran->id)->count());
        $this->assertSame(2, $lain->semester()->count());
    }

    private function service(): TahunAjaranService
    {
        return app(TahunAjaranService::class);
    }

    private function create(string $nama, string $mulai, string $selesai): TahunAjaran
    {
        return $this->service()->create([
            'nama' => $nama,
            'tanggal_mulai' => $mulai,
            'tanggal_selesai' => $selesai,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function assertValidationError(string $field, callable $operation): void
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
