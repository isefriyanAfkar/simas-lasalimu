<?php

namespace Tests\Feature\Filament\Pages;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PengaturanSekolahService;
use App\Domains\Foundation\Settings\PengaturanSekolah;
use App\Filament\Pages\KelolaPengaturanSekolah;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PengaturanSekolahPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_super_admin_opens_the_settings_page(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(KelolaPengaturanSekolah::getUrl())
            ->assertOk()
            ->assertSeeText('Pengaturan Sekolah');
    }

    public function test_operator_gets_403_on_the_settings_page(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->get(KelolaPengaturanSekolah::getUrl())
            ->assertForbidden();
    }

    public function test_operator_with_every_user_permission_still_gets_403(): void
    {
        $operator = User::factory()->operator()->create();
        $operator->givePermissionTo(PeranDanIzinSeeder::USER_PERMISSIONS);

        $this->actingAs($operator)
            ->get(KelolaPengaturanSekolah::getUrl())
            ->assertForbidden();
    }

    public function test_saving_the_form_stores_the_settings_and_logs_the_change(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(KelolaPengaturanSekolah::class)
            ->fillForm([
                'nama_sekolah' => 'SMA Negeri 1 Lasalimu',
                'npsn' => '40201234',
                'jenjang' => 'SMA',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        app()->forgetInstance(PengaturanSekolah::class);
        $this->assertSame('SMA Negeri 1 Lasalimu', app(PengaturanSekolah::class)->nama_sekolah);
        $this->assertSame(1, Activity::query()->where('event', 'pengaturan_sekolah_diubah')->count());
    }

    public function test_form_rejects_an_empty_school_name(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(KelolaPengaturanSekolah::class)
            ->fillForm(['nama_sekolah' => ''])
            ->call('save')
            ->assertHasFormErrors(['nama_sekolah' => 'required']);

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_form_rejects_an_npsn_that_is_not_eight_digits(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(KelolaPengaturanSekolah::class)
            ->fillForm(['nama_sekolah' => 'SMA Negeri 1 Lasalimu', 'npsn' => '1234567'])
            ->call('save')
            ->assertHasFormErrors(['npsn']);

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_uploaded_logo_is_stored_on_the_public_disk_and_replaces_the_old_one(): void
    {
        Storage::fake('public');
        $superAdmin = User::factory()->superAdmin()->create();
        Storage::disk('public')->put('logo-sekolah/lama.png', 'lama');
        app(PengaturanSekolahService::class)->simpan($superAdmin, ['nama_sekolah' => 'SMA Negeri 1 Lasalimu', 'logo' => 'logo-sekolah/lama.png']);
        $this->actingAs($superAdmin);

        // As in the browser: the current logo is removed from the field before
        // a new one is chosen. fillForm() alone would append to the old state.
        Livewire::test(KelolaPengaturanSekolah::class)
            ->set('data.logo', [])
            ->fillForm(['logo' => UploadedFile::fake()->image('logo.png', 200, 200)])
            ->call('save')
            ->assertHasNoFormErrors();

        app()->forgetInstance(PengaturanSekolah::class);
        $logo = app(PengaturanSekolah::class)->logo;
        $this->assertNotSame('logo-sekolah/lama.png', $logo);
        $this->assertStringStartsWith('logo-sekolah/', (string) $logo);
        Storage::disk('public')->assertExists((string) $logo);
        Storage::disk('public')->assertMissing('logo-sekolah/lama.png');
    }

    #[DataProvider('rejectedLogos')]
    public function test_form_rejects_a_logo_that_is_too_large_or_not_allowed(UploadedFile $file): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(KelolaPengaturanSekolah::class)
            ->fillForm(['nama_sekolah' => 'SMA Negeri 1 Lasalimu', 'logo' => $file])
            ->call('save')
            ->assertHasFormErrors(['logo']);
    }

    /**
     * @return array<string, array{UploadedFile}>
     */
    public static function rejectedLogos(): array
    {
        return [
            'lebih dari 1 MB' => [UploadedFile::fake()->image('logo.png')->size(1025)],
            'PDF' => [UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')],
            'SVG' => [UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')],
            'GIF' => [UploadedFile::fake()->image('logo.gif')],
        ];
    }
}
