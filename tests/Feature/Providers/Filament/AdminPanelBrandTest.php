<?php

namespace Tests\Feature\Providers\Filament;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PengaturanSekolahService;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPanelBrandTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.name' => 'SIMAS Uji Cadangan']);
        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_panel_name_follows_the_school_settings(): void
    {
        app(PengaturanSekolahService::class)->simpan(User::factory()->superAdmin()->create(), ['nama_sekolah' => 'SMA Negeri 1 Lasalimu']);

        $this->assertSame('SMA Negeri 1 Lasalimu', Filament::getPanel('admin')->getBrandName());
        $this->get(Filament::getPanel('admin')->getLoginUrl())
            ->assertOk()
            ->assertSee('SMA Negeri 1 Lasalimu');
    }

    public function test_panel_name_falls_back_to_app_name_before_settings_are_saved(): void
    {
        $this->assertSame('SIMAS Uji Cadangan', Filament::getPanel('admin')->getBrandName());
        $this->get(Filament::getPanel('admin')->getLoginUrl())
            ->assertOk()
            ->assertSee('SIMAS Uji Cadangan');
    }

    public function test_panel_logo_follows_the_school_settings(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logo-sekolah/logo.png', 'logo');
        app(PengaturanSekolahService::class)->simpan(User::factory()->superAdmin()->create(), [
            'nama_sekolah' => 'SMA Negeri 1 Lasalimu',
            'logo' => 'logo-sekolah/logo.png',
        ]);

        $this->assertSame('/storage/logo-sekolah/logo.png', Filament::getPanel('admin')->getBrandLogo());
    }

    /**
     * Uses the real public disk (not Storage::fake, whose URL is always
     * relative), so the URL comes from config/filesystems.php as in production.
     * An absolute URL built from APP_URL breaks when the site is opened from
     * another address.
     */
    public function test_panel_logo_url_is_relative_so_it_works_from_any_address(): void
    {
        $path = 'logo-sekolah/test-'.uniqid().'.png';
        Storage::disk('public')->put($path, 'logo');

        try {
            app(PengaturanSekolahService::class)->simpan(User::factory()->superAdmin()->create(), [
                'nama_sekolah' => 'SMA Negeri 1 Lasalimu',
                'logo' => $path,
            ]);

            $this->assertSame('/storage/'.$path, Filament::getPanel('admin')->getBrandLogo());
        } finally {
            Storage::disk('public')->delete($path);
        }
    }

    public function test_login_page_still_works_when_the_settings_table_is_missing(): void
    {
        config(['settings.repositories.database.table' => 'tabel_pengaturan_tidak_ada']);

        $this->get(Filament::getPanel('admin')->getLoginUrl())
            ->assertOk()
            ->assertSee('SIMAS Uji Cadangan');
    }
}
