<?php

namespace Tests\Feature\Domains\Academic\Services;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PelanggaranAturanAkademik;
use App\Domains\Academic\Services\RombelService;
use App\Domains\Academic\Services\TahunAjaranService;
use App\Domains\Foundation\Enums\Jenjang;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Settings\PengaturanSekolah;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RombelServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private TahunAjaran $tahunAjaran;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        $this->setJenjang(Jenjang::Sma);
        $this->tahunAjaran = app(TahunAjaranService::class)->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26']);
    }

    public function test_creates_a_rombel_whose_name_is_built_from_tingkat_and_nomor(): void
    {
        $rombel = $this->create(['tingkat' => 10, 'nomor' => 1, 'kapasitas' => 36]);

        $rombel = $rombel->fresh();
        $this->assertSame(Tingkat::X, $rombel->tingkat);
        $this->assertSame(1, $rombel->nomor);
        $this->assertSame(36, $rombel->kapasitas);
        $this->assertSame('X-1', $rombel->nama);
        $this->assertTrue($rombel->tahunAjaran->is($this->tahunAjaran));
    }

    /**
     * @return array<string, array{Tingkat, int, string}>
     */
    public static function names(): array
    {
        return [
            'I-1' => [Tingkat::I, 1, 'I-1'],
            'IV-12' => [Tingkat::IV, 12, 'IV-12'],
            'IX-3' => [Tingkat::IX, 3, 'IX-3'],
            'XI-2' => [Tingkat::XI, 2, 'XI-2'],
            'XII-99' => [Tingkat::XII, 99, 'XII-99'],
        ];
    }

    #[DataProvider('names')]
    public function test_the_standard_name_is_roman_tingkat_dash_nomor(Tingkat $tingkat, int $nomor, string $expected): void
    {
        $this->assertSame($expected, Rombel::namaDari($tingkat, $nomor));
        $this->assertSame($expected, Rombel::factory()->make(['tingkat' => $tingkat, 'nomor' => $nomor])->nama);
    }

    public function test_cannot_create_a_rombel_while_the_school_level_is_empty(): void
    {
        $this->setJenjang(null);

        try {
            $this->create(['tingkat' => 10, 'nomor' => 1]);
            $this->fail('A rombel was created without a school level.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Jenjang sekolah belum diisi. Isi dulu di menu Pengaturan Sekolah.'], $exception->errors()['tingkat']);
        }

        $this->assertSame(0, Rombel::query()->count());
    }

    /**
     * @return array<string, array{Jenjang, int}>
     */
    public static function tingkatOutsideJenjang(): array
    {
        return [
            'SD tingkat 7' => [Jenjang::Sd, 7],
            'SMP tingkat 6' => [Jenjang::Smp, 6],
            'SMP tingkat 10' => [Jenjang::Smp, 10],
            'SMA tingkat 9' => [Jenjang::Sma, 9],
            'SMK tingkat 13' => [Jenjang::Smk, 13],
            'SMA tingkat 0' => [Jenjang::Sma, 0],
        ];
    }

    #[DataProvider('tingkatOutsideJenjang')]
    public function test_rejects_a_tingkat_outside_the_school_level(Jenjang $jenjang, int $tingkat): void
    {
        $this->setJenjang($jenjang);

        $this->assertValidationError('tingkat', fn () => $this->create(['tingkat' => $tingkat, 'nomor' => 1]));

        $this->assertSame(0, Rombel::query()->count());
    }

    /**
     * @return array<string, array{Jenjang, int}>
     */
    public static function tingkatOnTheEdgesOfJenjang(): array
    {
        return [
            'SD tingkat 1' => [Jenjang::Sd, 1],
            'SD tingkat 6' => [Jenjang::Sd, 6],
            'SMP tingkat 7' => [Jenjang::Smp, 7],
            'SMP tingkat 9' => [Jenjang::Smp, 9],
            'SMA tingkat 10' => [Jenjang::Sma, 10],
            'SMK tingkat 12' => [Jenjang::Smk, 12],
        ];
    }

    #[DataProvider('tingkatOnTheEdgesOfJenjang')]
    public function test_accepts_a_tingkat_on_the_edges_of_the_school_level(Jenjang $jenjang, int $tingkat): void
    {
        $this->setJenjang($jenjang);

        $rombel = $this->create(['tingkat' => $tingkat, 'nomor' => 1]);

        $this->assertSame($tingkat, $rombel->fresh()->tingkat->value);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function outOfRange(): array
    {
        return [
            'nomor 0' => ['nomor', 0],
            'nomor 100' => ['nomor', 100],
            'nomor bukan bilangan bulat' => ['nomor', '1.5'],
            'nomor kosong' => ['nomor', null],
            'kapasitas 0' => ['kapasitas', 0],
            'kapasitas 100' => ['kapasitas', 100],
            'kapasitas bukan bilangan bulat' => ['kapasitas', '30.5'],
        ];
    }

    #[DataProvider('outOfRange')]
    public function test_rejects_a_nomor_or_kapasitas_out_of_range(string $field, mixed $value): void
    {
        $this->assertValidationError($field, fn () => $this->create(['tingkat' => 10, 'nomor' => 1, 'kapasitas' => 30, $field => $value]));

        $this->assertSame(0, Rombel::query()->count());
    }

    /**
     * @return array<string, array{int, int|null}>
     */
    public static function inRange(): array
    {
        return [
            'nomor 1, kapasitas kosong' => [1, null],
            'nomor 99, kapasitas 1' => [99, 1],
            'nomor 50, kapasitas 99' => [50, 99],
        ];
    }

    #[DataProvider('inRange')]
    public function test_accepts_nomor_and_kapasitas_on_their_edges(int $nomor, ?int $kapasitas): void
    {
        $rombel = $this->create(['tingkat' => 10, 'nomor' => $nomor, 'kapasitas' => $kapasitas]);

        $this->assertSame([$nomor, $kapasitas], [$rombel->fresh()->nomor, $rombel->fresh()->kapasitas]);
    }

    public function test_rejects_the_same_rombel_twice_in_one_school_year(): void
    {
        $this->create(['tingkat' => 10, 'nomor' => 1]);

        try {
            $this->create(['tingkat' => 10, 'nomor' => 1]);
            $this->fail('A duplicate rombel was created.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Rombel X-1 sudah ada di tahun ajaran 2026/2027.'], $exception->errors()['nomor']);
        }

        $this->assertSame(1, Rombel::query()->count());
    }

    public function test_the_same_rombel_may_exist_in_another_school_year(): void
    {
        $this->create(['tingkat' => 10, 'nomor' => 1]);
        $lain = app(TahunAjaranService::class)->create(['nama' => '2027/2028', 'tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2028-06-24']);

        $this->create(['tahun_ajaran_id' => $lain->id, 'tingkat' => 10, 'nomor' => 1]);

        $this->assertSame(2, Rombel::query()->count());
    }

    public function test_a_rombel_may_be_created_for_a_school_year_that_is_not_active(): void
    {
        $this->assertFalse($this->tahunAjaran->fresh()->status_aktif);

        $this->create(['tingkat' => 10, 'nomor' => 1]);

        $this->assertSame(1, $this->tahunAjaran->rombel()->count());
    }

    public function test_rejects_a_school_year_that_does_not_exist(): void
    {
        $this->assertValidationError('tahun_ajaran_id', fn () => $this->create(['tahun_ajaran_id' => 999999, 'tingkat' => 10, 'nomor' => 1]));
    }

    public function test_a_concurrent_create_of_the_same_rombel_ends_as_a_field_error(): void
    {
        Rombel::creating(function (Rombel $rombel): void {
            DB::table('rombel')->insert([
                'tahun_ajaran_id' => $rombel->tahun_ajaran_id,
                'tingkat' => $rombel->tingkat->value,
                'nomor' => $rombel->nomor,
            ]);
        });

        $this->assertValidationError('nomor', fn () => $this->create(['tingkat' => 10, 'nomor' => 1]));

        $this->assertSame(0, Rombel::query()->count());
    }

    public function test_update_may_keep_its_own_combination_and_rejects_another_rombels(): void
    {
        $this->create(['tingkat' => 10, 'nomor' => 1]);
        $rombel = $this->create(['tingkat' => 10, 'nomor' => 2]);

        $this->service()->update($rombel, $this->data(['tingkat' => 10, 'nomor' => 2, 'kapasitas' => 32]));
        $this->assertSame(32, $rombel->fresh()->kapasitas);

        $this->assertValidationError('nomor', fn () => $this->service()->update($rombel, $this->data(['tingkat' => 10, 'nomor' => 1])));
        $this->assertSame(2, $rombel->fresh()->nomor);
    }

    public function test_an_old_rombel_outside_a_changed_school_level_may_still_change_its_nomor_and_kapasitas(): void
    {
        $rombel = $this->create(['tingkat' => 10, 'nomor' => 1]);
        $this->setJenjang(Jenjang::Smp);

        $this->service()->update($rombel, $this->data(['tingkat' => 10, 'nomor' => 3, 'kapasitas' => 30]));

        $this->assertSame([3, 30], [$rombel->fresh()->nomor, $rombel->fresh()->kapasitas]);
    }

    public function test_the_school_level_is_checked_again_when_tingkat_changes(): void
    {
        $rombel = $this->create(['tingkat' => 10, 'nomor' => 1]);
        $this->setJenjang(Jenjang::Smp);

        $this->assertValidationError('tingkat', fn () => $this->service()->update($rombel, $this->data(['tingkat' => 11, 'nomor' => 1])));

        $this->assertSame(Tingkat::X, $rombel->fresh()->tingkat);
    }

    public function test_the_school_level_is_checked_again_when_the_school_year_changes(): void
    {
        $rombel = $this->create(['tingkat' => 10, 'nomor' => 1]);
        $lain = app(TahunAjaranService::class)->create(['nama' => '2027/2028', 'tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2028-06-24']);
        $this->setJenjang(null);

        $this->assertValidationError('tingkat', fn () => $this->service()->update($rombel, $this->data(['tahun_ajaran_id' => $lain->id, 'tingkat' => 10, 'nomor' => 1])));

        $this->assertTrue($rombel->fresh()->tahunAjaran->is($this->tahunAjaran));
    }

    public function test_create_update_and_delete_are_logged_by_the_trait(): void
    {
        $rombel = $this->create(['tingkat' => 10, 'nomor' => 1, 'kapasitas' => 36]);
        $this->service()->update($rombel, $this->data(['tingkat' => 10, 'nomor' => 2, 'kapasitas' => 36]));
        $this->service()->delete($rombel);

        $this->assertNull(Rombel::query()->find($rombel->id));
        $events = Activity::query()->where('subject_type', $rombel->getMorphClass())->where('subject_id', $rombel->id)->orderBy('id')->pluck('event')->all();
        $this->assertSame(['created', 'updated', 'deleted'], $events);

        $updated = Activity::query()->where('event', 'updated')->where('subject_type', $rombel->getMorphClass())->sole();
        $this->assertSame(['nomor' => 2], $updated->attribute_changes['attributes']);
    }

    public function test_display_order_is_by_tingkat_then_nomor(): void
    {
        $xi1 = $this->create(['tingkat' => 11, 'nomor' => 1]);
        $x10 = $this->create(['tingkat' => 10, 'nomor' => 10]);
        $x2 = $this->create(['tingkat' => 10, 'nomor' => 2]);

        $this->assertSame([$x2->id, $x10->id, $xi1->id], Rombel::query()->urutanTampilan()->pluck('id')->all());
    }

    public function test_cannot_delete_a_school_year_that_has_rombel(): void
    {
        $this->create(['tingkat' => 10, 'nomor' => 1]);
        $this->create(['tingkat' => 10, 'nomor' => 2]);

        try {
            app(TahunAjaranService::class)->delete(User::factory()->superAdmin()->create(), $this->tahunAjaran);
            $this->fail('A school year with rombel was deleted.');
        } catch (PelanggaranAturanAkademik $exception) {
            $this->assertSame('Tahun ajaran 2026/2027 sudah dipakai oleh 2 rombel. Hapus rombelnya dulu.', $exception->getMessage());
        }

        $this->assertNotNull($this->tahunAjaran->fresh());
        $this->assertSame(2, $this->tahunAjaran->semester()->count());
    }

    private function service(): RombelService
    {
        return app(RombelService::class);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function data(array $override): array
    {
        return ['tahun_ajaran_id' => $this->tahunAjaran->id, 'kapasitas' => null, ...$override];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function create(array $override): Rombel
    {
        return $this->service()->create($this->data($override));
    }

    private function setJenjang(?Jenjang $jenjang): void
    {
        $settings = app(PengaturanSekolah::class);
        $settings->jenjang = $jenjang;
        $settings->save();
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
