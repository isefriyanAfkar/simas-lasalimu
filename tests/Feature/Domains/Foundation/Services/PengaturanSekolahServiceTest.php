<?php

namespace Tests\Feature\Domains\Foundation\Services;

use App\Domains\Foundation\Enums\Jenjang;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PengaturanSekolahService;
use App\Domains\Foundation\Settings\PengaturanSekolah;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PengaturanSekolahServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_super_admin_saves_settings(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->service()->simpan($superAdmin, $this->validData());

        $settings = $this->freshSettings();
        $this->assertSame('SMA Negeri 1 Lasalimu', $settings->nama_sekolah);
        $this->assertSame('40201234', $settings->npsn);
        $this->assertSame(Jenjang::Sma, $settings->jenjang);
        $this->assertSame('Jl. Pendidikan No. 1', $settings->alamat);
        $this->assertSame('0402123456', $settings->telepon);
        $this->assertSame('tu@sman1lasalimu.sch.id', $settings->email);
    }

    public function test_logs_only_changed_fields_with_old_and_new_values(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->service()->simpan($superAdmin, $this->validData());

        $this->service()->simpan($superAdmin, [...$this->validData(), 'nama_sekolah' => 'SMA Negeri 2 Lasalimu', 'jenjang' => 'SMK']);

        $activity = Activity::query()->where('event', 'pengaturan_sekolah_diubah')->latest('id')->firstOrFail();
        $this->assertSame('pengaturan', $activity->log_name);
        $this->assertTrue($activity->causer->is($superAdmin));
        $this->assertSameKeysAndValues(['nama_sekolah' => 'SMA Negeri 2 Lasalimu', 'jenjang' => 'SMK'], $activity->attribute_changes['attributes']);
        $this->assertSameKeysAndValues(['nama_sekolah' => 'SMA Negeri 1 Lasalimu', 'jenjang' => 'SMA'], $activity->attribute_changes['old']);
    }

    public function test_first_save_logs_previous_null_values(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->service()->simpan($superAdmin, ['nama_sekolah' => 'SMA Negeri 1 Lasalimu']);

        $activity = Activity::query()->where('event', 'pengaturan_sekolah_diubah')->sole();
        $this->assertSame(['nama_sekolah' => 'SMA Negeri 1 Lasalimu'], $activity->attribute_changes['attributes']);
        $this->assertSame(['nama_sekolah' => null], $activity->attribute_changes['old']);
    }

    public function test_saving_without_changes_writes_no_log(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->service()->simpan($superAdmin, $this->validData());

        $this->service()->simpan($superAdmin, $this->validData());

        $this->assertSame(1, Activity::query()->where('event', 'pengaturan_sekolah_diubah')->count());
    }

    public function test_rejects_operator_without_saving_or_logging(): void
    {
        $operator = User::factory()->operator()->create();

        try {
            $this->service()->simpan($operator, $this->validData());
            $this->fail('Operator was allowed to save the school settings.');
        } catch (AuthorizationException) {
        }

        $this->assertNull($this->freshSettings()->nama_sekolah);
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_rejects_empty_school_name(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertValidationError('nama_sekolah', fn () => $this->service()->simpan($superAdmin, [...$this->validData(), 'nama_sekolah' => '']));

        $this->assertNull($this->freshSettings()->nama_sekolah);
    }

    #[DataProvider('invalidNpsn')]
    public function test_rejects_npsn_that_is_not_exactly_eight_digits(string $npsn): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertValidationError('npsn', fn () => $this->service()->simpan($superAdmin, [...$this->validData(), 'npsn' => $npsn]));

        $this->assertNull($this->freshSettings()->npsn);
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_optional_fields_may_be_empty(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->service()->simpan($superAdmin, [
            'nama_sekolah' => 'SMA Negeri 1 Lasalimu',
            'npsn' => null,
            'jenjang' => null,
            'alamat' => null,
            'telepon' => null,
            'email' => null,
            'logo' => null,
        ]);

        $settings = $this->freshSettings();
        $this->assertSame('SMA Negeri 1 Lasalimu', $settings->nama_sekolah);
        $this->assertNull($settings->npsn);
        $this->assertNull($settings->jenjang);
    }

    public function test_deletes_the_old_logo_when_it_is_replaced(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logo-sekolah/lama.png', 'lama');
        Storage::disk('public')->put('logo-sekolah/baru.png', 'baru');
        $superAdmin = User::factory()->superAdmin()->create();
        $this->service()->simpan($superAdmin, [...$this->validData(), 'logo' => 'logo-sekolah/lama.png']);

        $this->service()->simpan($superAdmin, [...$this->validData(), 'logo' => 'logo-sekolah/baru.png']);

        Storage::disk('public')->assertMissing('logo-sekolah/lama.png');
        Storage::disk('public')->assertExists('logo-sekolah/baru.png');
        $this->assertSame('logo-sekolah/baru.png', $this->freshSettings()->logo);
    }

    public function test_deletes_the_old_logo_when_it_is_removed(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logo-sekolah/lama.png', 'lama');
        $superAdmin = User::factory()->superAdmin()->create();
        $this->service()->simpan($superAdmin, [...$this->validData(), 'logo' => 'logo-sekolah/lama.png']);

        $this->service()->simpan($superAdmin, [...$this->validData(), 'logo' => null]);

        Storage::disk('public')->assertMissing('logo-sekolah/lama.png');
    }

    public function test_keeps_the_old_logo_when_saving_fails(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logo-sekolah/lama.png', 'lama');
        $superAdmin = User::factory()->superAdmin()->create();
        $this->service()->simpan($superAdmin, [...$this->validData(), 'logo' => 'logo-sekolah/lama.png']);

        $this->assertValidationError('npsn', fn () => $this->service()->simpan($superAdmin, [...$this->validData(), 'npsn' => '123', 'logo' => 'logo-sekolah/baru.png']));

        Storage::disk('public')->assertExists('logo-sekolah/lama.png');
        $this->assertSame('logo-sekolah/lama.png', $this->freshSettings()->logo);
    }

    public function test_panel_name_is_null_before_the_settings_are_first_saved(): void
    {
        $this->assertNull($this->service()->namaPanel());
    }

    public function test_panel_name_and_logo_follow_the_saved_settings(): void
    {
        Storage::fake('public');
        $superAdmin = User::factory()->superAdmin()->create();
        $this->service()->simpan($superAdmin, [...$this->validData(), 'logo' => 'logo-sekolah/logo.png']);

        $this->assertSame('SMA Negeri 1 Lasalimu', $this->service()->namaPanel());
        $this->assertSame(Storage::disk('public')->url('logo-sekolah/logo.png'), $this->service()->urlLogo());
    }

    public function test_panel_name_and_logo_are_null_when_the_settings_table_is_missing(): void
    {
        config(['settings.repositories.database.table' => 'tabel_pengaturan_tidak_ada']);

        $this->assertNull($this->service()->namaPanel());
        $this->assertNull($this->service()->urlLogo());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNpsn(): array
    {
        return [
            '7 digit' => ['1234567'],
            '9 digit' => ['123456789'],
            'ada huruf' => ['1234567a'],
            'ada spasi' => ['1234 567'],
        ];
    }

    private function service(): PengaturanSekolahService
    {
        return app(PengaturanSekolahService::class);
    }

    private function freshSettings(): PengaturanSekolah
    {
        app()->forgetInstance(PengaturanSekolah::class);

        return app(PengaturanSekolah::class)->refresh();
    }

    /**
     * @return array<string, string>
     */
    private function validData(): array
    {
        return [
            'nama_sekolah' => 'SMA Negeri 1 Lasalimu',
            'npsn' => '40201234',
            'jenjang' => 'SMA',
            'alamat' => 'Jl. Pendidikan No. 1',
            'telepon' => '0402123456',
            'email' => 'tu@sman1lasalimu.sch.id',
        ];
    }

    private function assertValidationError(string $field, callable $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());

            return;
        }

        $this->fail("Expected a validation error on [{$field}].");
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertSameKeysAndValues(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }
}
