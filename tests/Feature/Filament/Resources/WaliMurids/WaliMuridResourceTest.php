<?php

namespace Tests\Feature\Filament\Resources\WaliMurids;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\SiswaWali;
use App\Domains\People\Models\WaliMurid;
use App\Domains\People\Services\SiswaWaliService;
use App\Filament\Resources\WaliMurids\Pages\EditWaliMurid;
use App\Filament\Resources\WaliMurids\Pages\ListWaliMurids;
use App\Filament\Resources\WaliMurids\WaliMuridResource;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WaliMuridResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
        $this->operator = User::factory()->operator()->create(['name' => 'Operator', 'username' => 'operator']);
        $this->actingAs($this->operator);
    }

    public function test_the_list_shows_the_name_a_masked_phone_number_and_the_children(): void
    {
        $budi = $this->siswa('Budi');
        $ahmad = $this->tambahkan($budi, ['name' => 'Ahmad', 'no_hp' => '+6281234567890'])->waliMurid;
        $this->tambahkan($this->siswa('Ani'), ['wali' => 'ada', 'wali_murid_id' => $ahmad->id]);

        $this->get(WaliMuridResource::getUrl('index'))->assertOk()->assertSee('Wali Murid');

        Livewire::test(ListWaliMurids::class)
            ->assertCanSeeTableRecords([$ahmad])
            ->assertTableColumnStateSet('user.name', 'Ahmad', $ahmad)
            ->assertTableColumnStateSet('no_hp', '0812****7890', $ahmad)
            ->assertTableColumnStateSet('anak', 'Ani, Budi', $ahmad)
            ->assertDontSeeHtml('6281234567890');
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function deniedRoles(): array
    {
        return [
            'guru' => ['guru'],
            'tanpa role' => [null],
        ];
    }

    #[DataProvider('deniedRoles')]
    public function test_other_roles_cannot_open_the_screen(?string $role): void
    {
        $user = User::factory()->create();

        if ($role !== null) {
            $user->assignRole($role);
        }

        $this->actingAs($user)->get(WaliMuridResource::getUrl('index'))->assertForbidden();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function searches(): array
    {
        return [
            'nama' => ['ahmad'],
            '08' => ['0812345'],
            '+62' => ['+62812345'],
        ];
    }

    #[DataProvider('searches')]
    public function test_the_list_is_searched_by_name_and_phone_number(string $kataKunci): void
    {
        $ahmad = $this->tambahkan($this->siswa('Budi'), ['name' => 'Ahmad', 'no_hp' => '081234567890'])->waliMurid;
        $siti = $this->tambahkan($this->siswa('Ani'), ['name' => 'Siti', 'hubungan' => 'ibu', 'no_hp' => '085299990000'])->waliMurid;

        Livewire::test(ListWaliMurids::class)
            ->searchTable($kataKunci)
            ->assertCanSeeTableRecords([$ahmad])
            ->assertCanNotSeeTableRecords([$siti]);
    }

    public function test_editing_a_parent_and_the_gender_rule_on_the_form(): void
    {
        $ahmad = $this->tambahkan($this->siswa('Budi'), ['name' => 'Ahmad'])->waliMurid;

        Livewire::test(EditWaliMurid::class, ['record' => $ahmad->getRouteKey()])
            ->assertSchemaStateSet(['name' => 'Ahmad', 'jenis_kelamin' => 'laki-laki', 'nik' => null])
            ->assertFormFieldDoesNotExist('username')
            ->fillForm(['jenis_kelamin' => 'perempuan'])
            ->call('save')
            ->assertHasFormErrors(['jenis_kelamin'])
            ->fillForm(['jenis_kelamin' => 'laki-laki', 'no_hp' => '0811000222', 'pekerjaan' => 'Nelayan'])
            ->call('save')
            ->assertHasNoFormErrors();

        $ahmad = $ahmad->fresh();
        $this->assertSame('62811000222', $ahmad->user->no_hp);
        $this->assertSame('Nelayan', $ahmad->pekerjaan);
    }

    public function test_marking_as_died_is_refused_for_a_main_contact_and_works_otherwise(): void
    {
        $budi = $this->siswa('Budi');
        $ahmad = $this->tambahkan($budi, ['name' => 'Ahmad'])->waliMurid;

        Livewire::test(ListWaliMurids::class)
            ->callAction(TestAction::make('tandaiMeninggal')->table($ahmad))
            ->assertNotified('Ahmad masih wali utama Budi. Pilih wali utama lain dulu.');

        $siti = $this->tambahkan($budi, ['name' => 'Siti', 'hubungan' => 'ibu']);
        app(SiswaWaliService::class)->jadikanUtama($this->operator, $siti);

        Livewire::test(ListWaliMurids::class)
            ->callAction(TestAction::make('tandaiMeninggal')->table($ahmad))
            ->assertNotified('Ahmad ditandai meninggal');

        $this->assertTrue($ahmad->fresh()->meninggal);

        Livewire::test(ListWaliMurids::class)
            ->assertActionHidden(TestAction::make('tandaiMeninggal')->table($ahmad->fresh()))
            ->callAction(TestAction::make('batalkanMeninggal')->table($ahmad->fresh()))
            ->assertNotified('Tanda meninggal Ahmad dibatalkan');

        $this->assertFalse($ahmad->fresh()->meninggal);
    }

    public function test_only_a_parent_without_children_can_be_deleted(): void
    {
        $budi = $this->siswa('Budi');
        $ahmad = $this->tambahkan($budi, ['name' => 'Ahmad'])->waliMurid;
        $paman = $this->tambahkan($budi, ['name' => 'Paman', 'hubungan' => 'wali']);
        app(SiswaWaliService::class)->lepas($this->operator, $paman);
        $tanpaAnak = $paman->waliMurid;

        Livewire::test(ListWaliMurids::class)
            ->assertActionHidden(TestAction::make('hapus')->table($ahmad))
            ->callAction(TestAction::make('hapus')->table($tanpaAnak))
            ->assertNotified('Paman dihapus');

        $this->assertNull(WaliMurid::query()->find($tanpaAnak->id));
        $this->assertNull(User::query()->find($tanpaAnak->user_id));
        $this->assertNotNull($ahmad->fresh());
    }

    public function test_there_is_no_create_page(): void
    {
        $this->assertArrayNotHasKey('create', WaliMuridResource::getPages());

        Livewire::test(ListWaliMurids::class)->assertActionDoesNotExist('create');
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function tambahkan(Siswa $siswa, array $override): SiswaWali
    {
        return app(SiswaWaliService::class)->tambahkan($this->operator, $siswa, ['wali' => 'baru', 'hubungan' => 'ayah', ...$override]);
    }

    private function siswa(string $nama): Siswa
    {
        return Siswa::factory()->for(User::factory()->state(['name' => $nama]))->create();
    }
}
