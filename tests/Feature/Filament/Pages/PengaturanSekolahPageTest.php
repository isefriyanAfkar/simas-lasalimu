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

    public function test_operator_cannot_mount_the_page_to_call_save(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(KelolaPengaturanSekolah::class)->assertForbidden();

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_saving_reloads_the_page_so_the_new_brand_shows(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(KelolaPengaturanSekolah::class)
            ->fillForm(['nama_sekolah' => 'SMA Negeri 1 Lasalimu'])
            ->call('save')
            ->assertRedirect(KelolaPengaturanSekolah::getUrl());
    }

    public function test_saving_another_field_keeps_the_current_logo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logo-sekolah/logo.png', 'logo');
        $superAdmin = User::factory()->superAdmin()->create();
        app(PengaturanSekolahService::class)->simpan($superAdmin, ['nama_sekolah' => 'SMA Negeri 1 Lasalimu', 'logo' => 'logo-sekolah/logo.png']);
        $this->actingAs($superAdmin);

        Livewire::test(KelolaPengaturanSekolah::class)
            ->fillForm(['alamat' => 'Jl. Baru No. 2'])
            ->call('save')
            ->assertHasNoFormErrors();

        Storage::disk('public')->assertExists('logo-sekolah/logo.png');
        app()->forgetInstance(PengaturanSekolah::class);
        $this->assertSame('logo-sekolah/logo.png', app(PengaturanSekolah::class)->logo);
    }

    /**
     * The FileUpload preview is fetched by the browser from this URL; an
     * absolute APP_URL-based URL leaves the field spinning when the site is
     * opened from another address. Uses the real public disk on purpose.
     */
    public function test_logo_preview_url_in_the_form_is_relative(): void
    {
        $path = 'logo-sekolah/test-'.uniqid().'.png';
        Storage::disk('public')->put($path, 'logo');
        $superAdmin = User::factory()->superAdmin()->create();

        try {
            app(PengaturanSekolahService::class)->simpan($superAdmin, ['nama_sekolah' => 'SMA Negeri 1 Lasalimu', 'logo' => $path]);
            $this->actingAs($superAdmin);

            $page = Livewire::test(KelolaPengaturanSekolah::class)->instance();
            $logoField = $page->form->getFlatFields(withHidden: true)['logo'];

            $this->assertSame('/storage/'.$path, $logoField->getUploadedFile($path, null)['url'] ?? null);
        } finally {
            Storage::disk('public')->delete($path);
        }
    }

    public function test_tampered_logo_path_is_rejected_and_no_file_is_deleted(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('lain/rahasia.pdf', 'rahasia');
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(KelolaPengaturanSekolah::class)
            ->set('data.nama_sekolah', 'SMA Negeri 1 Lasalimu')
            ->set('data.logo', ['palsu' => 'lain/rahasia.pdf'])
            ->call('save')
            ->assertHasFormErrors(['logo']);

        app()->forgetInstance(PengaturanSekolah::class);
        $this->assertNull(app(PengaturanSekolah::class)->logo);
        Storage::disk('public')->assertExists('lain/rahasia.pdf');
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
