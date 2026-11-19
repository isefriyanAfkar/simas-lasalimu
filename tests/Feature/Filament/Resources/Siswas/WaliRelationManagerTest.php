<?php

namespace Tests\Feature\Filament\Resources\Siswas;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\HubunganWali;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\SiswaWali;
use App\Domains\People\Models\WaliMurid;
use App\Domains\People\Services\SiswaWaliService;
use App\Filament\Resources\Siswas\Pages\EditSiswa;
use App\Filament\Resources\Siswas\Pages\ListSiswas;
use App\Filament\Resources\Siswas\Pages\ViewSiswa;
use App\Filament\Resources\Siswas\RelationManagers\WaliRelationManager;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Wali murid section on the student's page, and the two Siswa list
 * filters that help collect parents' phone numbers.
 */
class WaliRelationManagerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string NIK = '7404012345670005';

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
        $this->operator = User::factory()->operator()->create(['name' => 'Operator', 'username' => 'operator']);
        $this->actingAs($this->operator);
    }

    public function test_adding_a_new_parent_through_the_form(): void
    {
        $budi = $this->siswa('Budi');

        $this->section($budi)
            ->callAction(TestAction::make('tambah')->table(), data: ['wali' => 'baru', 'name' => 'Ahmad', 'no_hp' => '081234567890', 'hubungan' => 'ayah'])
            ->assertHasNoFormErrors()
            ->assertNotified('Wali murid ditambahkan');

        $siswaWali = SiswaWali::query()->sole();
        $this->assertSame('Ahmad', $siswaWali->waliMurid->user->name);
        $this->assertTrue($siswaWali->utama);
        $this->assertFalse($siswaWali->waliMurid->user->status_aktif);

        $this->section($budi)
            ->assertCanSeeTableRecords([$siswaWali])
            ->assertTableColumnStateSet('no_hp', '0812****7890', $siswaWali)
            ->assertTableColumnFormattedStateSet('utama', 'Utama', $siswaWali);
    }

    public function test_choosing_an_existing_parent_links_them_to_a_sibling(): void
    {
        $ahmad = $this->tambahkan($this->siswa('Budi'), ['name' => 'Ahmad', 'no_hp' => '081234567890'])->waliMurid;
        $ani = $this->siswa('Ani');

        $this->section($ani)
            ->mountAction(TestAction::make('tambah')->table())
            ->assertFormFieldExists('wali_murid_id', fn (Select $field): bool => $field->getSearchResults('+62812345') === [$ahmad->id => 'Ahmad · 0812****7890 · anak: Budi'])
            ->fillForm(['wali' => 'ada', 'wali_murid_id' => $ahmad->id, 'hubungan' => 'ayah'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame(1, WaliMurid::query()->count());
        $this->assertSame(2, $ahmad->siswaWali()->count());
    }

    public function test_a_new_parent_with_a_known_phone_number_is_warned_and_may_use_the_existing_one(): void
    {
        $ahmad = $this->tambahkan($this->siswa('Budi'), ['name' => 'Ahmad', 'no_hp' => '081234567890'])->waliMurid;
        $ani = $this->siswa('Ani');

        $this->section($ani)
            ->mountAction(TestAction::make('tambah')->table())
            ->fillForm(['wali' => 'baru', 'name' => 'Ahmad Lagi', 'no_hp' => '+62 812-3456-7890', 'hubungan' => 'ayah'])
            ->assertFormFieldVisible('pakai_wali')
            ->assertFormFieldExists('pakai_wali', fn (Radio $field): bool => $field->getOptions() === [
                $ahmad->id => 'Pakai Ahmad · 0812****7890 · anak: Budi',
                'baru' => 'Tetap buat wali baru',
            ])
            ->fillForm(['pakai_wali' => $ahmad->id])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame(1, WaliMurid::query()->count());
        $this->assertTrue(SiswaWali::query()->where('siswa_id', $ani->id)->sole()->waliMurid->is($ahmad));
    }

    public function test_the_warning_never_blocks_a_new_parent(): void
    {
        $this->tambahkan($this->siswa('Budi'), ['name' => 'Ahmad', 'no_hp' => '081234567890']);
        $ani = $this->siswa('Ani');

        $this->section($ani)
            ->callAction(TestAction::make('tambah')->table(), data: ['wali' => 'baru', 'name' => 'Siti', 'no_hp' => '081234567890', 'pakai_wali' => 'baru', 'hubungan' => 'ibu'])
            ->assertHasNoFormErrors();

        $this->assertSame(2, WaliMurid::query()->count());
    }

    public function test_no_warning_without_a_matching_parent(): void
    {
        $this->tambahkan($this->siswa('Budi'), ['name' => 'Ahmad', 'no_hp' => '081234567890']);

        $this->section($this->siswa('Ani'))
            ->mountAction(TestAction::make('tambah')->table())
            ->fillForm(['wali' => 'baru', 'name' => 'Siti', 'no_hp' => '081299990000'])
            ->assertFormFieldHidden('pakai_wali');
    }

    public function test_service_errors_land_on_the_form_fields(): void
    {
        $guru = User::factory()->create(['name' => 'Hafida']);
        $guru->isiNik(self::NIK);
        $guru->save();
        Ptk::factory()->for($guru)->create();

        $this->section($this->siswa('Budi'))
            ->callAction(TestAction::make('tambah')->table(), data: ['wali' => 'baru', 'name' => 'Siti', 'jenis_kelamin' => 'laki-laki', 'nik' => self::NIK, 'hubungan' => 'ibu'])
            ->assertHasFormErrors(['jenis_kelamin', 'nik']);

        $this->assertSame(0, WaliMurid::query()->count());
    }

    public function test_changing_the_relation_making_main_contact_and_letting_go(): void
    {
        $budi = $this->siswa('Budi');
        $ahmad = $this->tambahkan($budi, ['name' => 'Ahmad']);
        $siti = $this->tambahkan($budi, ['name' => 'Paman', 'hubungan' => 'wali']);

        $this->section($budi)
            ->assertActionHidden(TestAction::make('lepas')->table($ahmad))
            ->assertActionHidden(TestAction::make('jadikanUtama')->table($ahmad))
            ->callAction(TestAction::make('ubahHubungan')->table($siti), data: ['hubungan' => 'ayah'])
            ->assertHasFormErrors(['hubungan']);

        $this->section($budi)
            ->callAction(TestAction::make('jadikanUtama')->table($siti))
            ->assertNotified('Paman menjadi wali utama');

        $this->assertTrue($siti->fresh()->utama);

        $this->section($budi)
            ->callAction(TestAction::make('lepas')->table($ahmad->fresh()))
            ->assertNotified('Ahmad dilepas');

        $this->assertSame([$siti->id], SiswaWali::query()->pluck('id')->all());
    }

    public function test_the_section_is_read_only_on_the_view_page(): void
    {
        $budi = $this->siswa('Budi');
        $ahmad = $this->tambahkan($budi, ['name' => 'Ahmad']);

        Livewire::test(WaliRelationManager::class, ['ownerRecord' => $budi, 'pageClass' => ViewSiswa::class])
            ->assertCanSeeTableRecords([$ahmad])
            ->assertActionHidden(TestAction::make('tambah')->table())
            ->assertActionHidden(TestAction::make('ubahHubungan')->table($ahmad));
    }

    public function test_the_section_needs_the_right_to_view_parents(): void
    {
        $budi = $this->siswa('Budi');
        $user = User::factory()->create();
        $user->givePermissionTo('View:Siswa');
        $this->actingAs($user);

        $this->assertFalse(WaliRelationManager::canViewForRecord($budi, EditSiswa::class));

        $user->givePermissionTo('ViewAny:WaliMurid');
        $this->assertTrue(WaliRelationManager::canViewForRecord($budi, EditSiswa::class));
    }

    public function test_the_student_list_filters_find_missing_parents_and_missing_phone_numbers(): void
    {
        $tanpaWali = $this->siswa('Ani');
        $tanpaNomor = $this->siswa('Budi');
        $lengkap = $this->siswa('Citra');
        $utamaTanpaNomor = $this->siswa('Dodi');
        $this->tambahkan($tanpaNomor, ['name' => 'Ahmad']);
        $this->tambahkan($lengkap, ['name' => 'Bambang', 'no_hp' => '081234567890']);
        $this->tambahkan($lengkap, ['name' => 'Paman Tanpa Nomor', 'hubungan' => 'wali']);
        $this->tambahkan($utamaTanpaNomor, ['name' => 'Candra']);
        $this->tambahkan($utamaTanpaNomor, ['name' => 'Dewi', 'hubungan' => 'ibu', 'no_hp' => '081299990000']);

        Livewire::test(ListSiswas::class)
            ->filterTable('belum_punya_wali', true)
            ->assertCanSeeTableRecords([$tanpaWali])
            ->assertCanNotSeeTableRecords([$tanpaNomor, $lengkap, $utamaTanpaNomor])
            ->resetTableFilters()
            ->filterTable('wali_utama_tanpa_no_hp', true)
            ->assertCanSeeTableRecords([$tanpaNomor, $utamaTanpaNomor])
            ->assertCanNotSeeTableRecords([$tanpaWali, $lengkap]);
    }

    private function section(Siswa $siswa): mixed
    {
        return Livewire::test(WaliRelationManager::class, ['ownerRecord' => $siswa, 'pageClass' => EditSiswa::class]);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function tambahkan(Siswa $siswa, array $override): SiswaWali
    {
        return app(SiswaWaliService::class)->tambahkan($this->operator, $siswa, ['wali' => 'baru', 'hubungan' => HubunganWali::Ayah->value, ...$override]);
    }

    private function siswa(string $nama): Siswa
    {
        return Siswa::factory()->for(User::factory()->state(['name' => $nama]))->create();
    }
}
