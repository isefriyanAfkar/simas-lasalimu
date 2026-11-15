<?php

namespace Tests\Feature\Domains\Academic\Services;

use App\Domains\Academic\Enums\JenisSemester;
use App\Domains\Academic\Models\Semester;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PelanggaranAturanAkademik;
use App\Domains\Academic\Services\SemesterService;
use App\Domains\Academic\Services\TahunAjaranService;
use App\Domains\Foundation\Models\User;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SemesterServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_updates_the_dates_of_a_semester(): void
    {
        $ganjil = $this->schoolYear()->semesterGanjil;

        app(SemesterService::class)->updateDates($ganjil, ['tanggal_mulai' => '2026-07-20', 'tanggal_selesai' => '2026-12-19']);

        $ganjil->refresh();
        $this->assertSame('2026-07-20', $ganjil->tanggal_mulai->toDateString());
        $this->assertSame('2026-12-19', $ganjil->tanggal_selesai->toDateString());
    }

    /**
     * School year: 2026-07-13 .. 2027-06-26.
     *
     * @return array<string, array{JenisSemester, string, string, string}>
     */
    public static function datesOutsideTheSchoolYear(): array
    {
        return [
            'Ganjil mulai sebelum tahun ajaran' => [JenisSemester::Ganjil, '2026-07-12', '2026-12-31', 'tanggal_mulai'],
            'Genap selesai sesudah tahun ajaran' => [JenisSemester::Genap, '2027-01-01', '2027-06-27', 'tanggal_selesai'],
        ];
    }

    #[DataProvider('datesOutsideTheSchoolYear')]
    public function test_rejects_dates_outside_the_school_year(JenisSemester $jenis, string $mulai, string $selesai, string $field): void
    {
        $semester = $this->schoolYear()->semester()->where('jenis', $jenis->value)->sole();
        $before = [$semester->tanggal_mulai->toDateString(), $semester->tanggal_selesai->toDateString()];

        $this->assertValidationError($field, fn () => app(SemesterService::class)->updateDates($semester, ['tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai]));

        $semester->refresh();
        $this->assertSame($before, [$semester->tanggal_mulai->toDateString(), $semester->tanggal_selesai->toDateString()]);
    }

    /**
     * Genap starts on 2027-01-01.
     *
     * @return array<string, array{string}>
     */
    public static function ganjilEndsTooLate(): array
    {
        return [
            'Ganjil selesai di hari Genap mulai' => ['2027-01-01'],
            'Ganjil selesai sesudah Genap mulai' => ['2027-01-10'],
        ];
    }

    #[DataProvider('ganjilEndsTooLate')]
    public function test_rejects_ganjil_that_does_not_end_before_genap_starts(string $ganjilSelesai): void
    {
        $ganjil = $this->schoolYear()->semesterGanjil;

        $this->assertValidationError('tanggal_selesai', fn () => app(SemesterService::class)->updateDates($ganjil, ['tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => $ganjilSelesai]));
    }

    public function test_rejects_genap_that_starts_before_ganjil_ends(): void
    {
        $genap = $this->schoolYear()->semesterGenap;

        $this->assertValidationError('tanggal_mulai', fn () => app(SemesterService::class)->updateDates($genap, ['tanggal_mulai' => '2026-12-20', 'tanggal_selesai' => '2027-06-26']));
    }

    public function test_rejects_an_end_date_before_the_start_date(): void
    {
        $ganjil = $this->schoolYear()->semesterGanjil;

        $this->assertValidationError('tanggal_selesai', fn () => app(SemesterService::class)->updateDates($ganjil, ['tanggal_mulai' => '2026-10-01', 'tanggal_selesai' => '2026-09-30']));
    }

    public function test_activating_a_semester_deactivates_the_other_one(): void
    {
        $this->travelTo('2026-09-15');
        $tahunAjaran = $this->schoolYear();
        app(TahunAjaranService::class)->activate($this->superAdmin(), $tahunAjaran);

        app(SemesterService::class)->activate($this->superAdmin(), $tahunAjaran->semesterGenap);

        $this->assertFalse($tahunAjaran->semesterGanjil->fresh()->status_aktif);
        $this->assertTrue($tahunAjaran->semesterGenap->fresh()->status_aktif);
        $this->assertSame(1, Semester::query()->where('status_aktif', true)->count());
    }

    public function test_activating_a_semester_is_logged_with_the_actor(): void
    {
        $this->travelTo('2026-09-15');
        $tahunAjaran = $this->schoolYear();
        app(TahunAjaranService::class)->activate($this->superAdmin(), $tahunAjaran);
        $operator = User::factory()->operator()->create();

        app(SemesterService::class)->activate($operator, $tahunAjaran->semesterGenap);

        $log = Activity::query()->where('event', 'semester_diaktifkan')->latest('id')->first();
        $this->assertTrue($log->causer->is($operator));
        $this->assertTrue($log->subject->is($tahunAjaran->semesterGenap));
        $this->assertSame(['status_aktif' => true], $log->attribute_changes['attributes']);
    }

    public function test_rejects_activating_a_semester_of_an_inactive_school_year(): void
    {
        $this->travelTo('2026-09-15');
        $aktif = $this->schoolYear();
        app(TahunAjaranService::class)->activate($this->superAdmin(), $aktif);
        $lain = app(TahunAjaranService::class)->create(['nama' => '2027/2028', 'tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2028-06-24']);

        try {
            app(SemesterService::class)->activate($this->superAdmin(), $lain->semesterGanjil);
            $this->fail('A semester of an inactive school year was activated.');
        } catch (PelanggaranAturanAkademik $exception) {
            $this->assertSame(PelanggaranAturanAkademik::semesterBukanMilikTahunAjaranAktif()->getMessage(), $exception->getMessage());
        }

        $this->assertFalse($lain->semesterGanjil->fresh()->status_aktif);
        $this->assertTrue($aktif->semesterGanjil->fresh()->status_aktif);
    }

    private function schoolYear(): TahunAjaran
    {
        return app(TahunAjaranService::class)->create([
            'nama' => '2026/2027',
            'tanggal_mulai' => '2026-07-13',
            'tanggal_selesai' => '2027-06-26',
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
