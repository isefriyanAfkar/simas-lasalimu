<?php

namespace Tests\Feature\Filament\Resources\Rombels;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\TahunAjaranService;
use App\Domains\Foundation\Enums\Jenjang;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Settings\PengaturanSekolah;
use App\Filament\Resources\Rombels\Pages\CreateRombel;
use App\Filament\Resources\Rombels\Pages\EditRombel;
use App\Filament\Resources\Rombels\Pages\ListRombels;
use App\Filament\Resources\Rombels\RombelResource;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RombelResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private TahunAjaran $aktif;

    private TahunAjaran $lain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
        $this->setJenjang(Jenjang::Sma);

        $service = app(TahunAjaranService::class);
        $this->aktif = $service->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26']);
        $this->lain = $service->create(['nama' => '2027/2028', 'tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2028-06-24']);
        $this->travelTo('2026-09-15');
        $service->activate(User::factory()->superAdmin()->create(), $this->aktif);
    }

    public function test_operator_opens_the_list(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->get(RombelResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Rombel');
    }

    public function test_user_without_role_cannot_open_any_page(): void
    {
        $rombel = $this->rombel($this->aktif, Tingkat::X, 1);
        $this->actingAs(User::factory()->create());

        $this->get(RombelResource::getUrl('index'))->assertForbidden();
        $this->get(RombelResource::getUrl('create'))->assertForbidden();
        $this->get(RombelResource::getUrl('edit', ['record' => $rombel]))->assertForbidden();
    }

    public function test_list_shows_the_active_school_year_by_default_ordered_by_tingkat_then_nomor(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $xi1 = $this->rombel($this->aktif, Tingkat::XI, 1);
        $x10 = $this->rombel($this->aktif, Tingkat::X, 10);
        $x2 = $this->rombel($this->aktif, Tingkat::X, 2);
        $tahunLain = $this->rombel($this->lain, Tingkat::X, 1);

        Livewire::test(ListRombels::class)
            ->assertCanSeeTableRecords([$x2, $x10, $xi1], inOrder: true)
            ->assertCanNotSeeTableRecords([$tahunLain])
            ->assertTableColumnStateSet('nama', 'X-2', $x2)
            ->assertTableColumnStateSet('tahunAjaran.nama', '2026/2027', $x2)
            ->filterTable('tahun_ajaran_id', $this->lain->id)
            ->assertCanSeeTableRecords([$tahunLain])
            ->assertCanNotSeeTableRecords([$x2]);
    }

    public function test_list_can_be_filtered_by_tingkat(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $x = $this->rombel($this->aktif, Tingkat::X, 1);
        $xi = $this->rombel($this->aktif, Tingkat::XI, 1);

        Livewire::test(ListRombels::class)
            ->filterTable('tingkat', Tingkat::XI->value)
            ->assertCanSeeTableRecords([$xi])
            ->assertCanNotSeeTableRecords([$x]);
    }

    public function test_operator_creates_a_rombel_through_the_form_for_the_active_school_year_by_default(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(CreateRombel::class)
            ->assertSchemaStateSet(['tahun_ajaran_id' => $this->aktif->id])
            ->fillForm(['tingkat' => 10, 'nomor' => 1, 'kapasitas' => 36])
            ->call('create')
            ->assertHasNoFormErrors();

        $rombel = Rombel::query()->sole();
        $this->assertSame('X-1', $rombel->nama);
        $this->assertTrue($rombel->tahunAjaran->is($this->aktif));
    }

    public function test_the_tingkat_options_follow_the_school_level(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $this->setJenjang(Jenjang::Smp);

        Livewire::test(CreateRombel::class)
            ->fillForm(['tingkat' => 10, 'nomor' => 1])
            ->call('create')
            ->assertHasFormErrors(['tingkat']);

        $this->assertSame(0, Rombel::query()->count());
    }

    public function test_an_empty_school_level_is_explained_on_the_form_and_blocks_saving(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $this->setJenjang(null);

        Livewire::test(CreateRombel::class)
            ->assertSee('Jenjang sekolah belum diisi. Isi dulu di menu Pengaturan Sekolah.')
            ->fillForm(['tingkat' => 10, 'nomor' => 1])
            ->call('create')
            ->assertHasFormErrors(['tingkat']);

        $this->assertSame(0, Rombel::query()->count());
    }

    public function test_a_duplicate_rombel_is_shown_on_the_nomor_field(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $this->rombel($this->aktif, Tingkat::X, 1);

        Livewire::test(CreateRombel::class)
            ->fillForm(['tingkat' => 10, 'nomor' => 1])
            ->call('create')
            ->assertHasFormErrors(['nomor']);

        $this->assertSame(1, Rombel::query()->count());
    }

    public function test_operator_updates_a_rombel_through_the_form(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $rombel = $this->rombel($this->aktif, Tingkat::X, 1);

        Livewire::test(EditRombel::class, ['record' => $rombel->getRouteKey()])
            ->fillForm(['nomor' => 4, 'kapasitas' => 30])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('X-4', $rombel->fresh()->nama);
        $this->assertSame(30, $rombel->fresh()->kapasitas);
    }

    public function test_operator_sees_no_delete_action(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $rombel = $this->rombel($this->aktif, Tingkat::X, 1);

        Livewire::test(ListRombels::class)
            ->assertActionHidden(TestAction::make('hapus')->table($rombel))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

        Livewire::test(EditRombel::class, ['record' => $rombel->getRouteKey()])
            ->assertActionDoesNotExist('delete');
    }

    public function test_super_admin_deletes_a_rombel(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $rombel = $this->rombel($this->aktif, Tingkat::X, 1);

        Livewire::test(ListRombels::class)
            ->callAction(TestAction::make('hapus')->table($rombel))
            ->assertNotified('Rombel X-1 dihapus');

        $this->assertNull(Rombel::query()->find($rombel->id));
    }

    private function rombel(TahunAjaran $tahunAjaran, Tingkat $tingkat, int $nomor): Rombel
    {
        return Rombel::factory()->for($tahunAjaran)->create(['tingkat' => $tingkat, 'nomor' => $nomor]);
    }

    private function setJenjang(?Jenjang $jenjang): void
    {
        $settings = app(PengaturanSekolah::class);
        $settings->jenjang = $jenjang;
        $settings->save();
    }
}
