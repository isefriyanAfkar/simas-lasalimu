<?php

namespace Tests\Feature\Filament\Resources\Siswas;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\PenempatanRombel;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Foundation\Enums\Jenjang;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Settings\PengaturanSekolah;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use App\Filament\Resources\Rombels\Pages\EditRombel;
use App\Filament\Resources\Rombels\Pages\ListRombels;
use App\Filament\Resources\Rombels\RelationManagers\AnggotaRelationManager;
use App\Filament\Resources\Siswas\Pages\ListSiswas;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The rombel placement on the screens: the rombel column and filters of the
 * Siswa list, the place/move, delete and bulk actions, and the read-only
 * member list on the Rombel edit page.
 */
class PenempatanRombelResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private TahunAjaran $tahunAjaran;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
        $settings = app(PengaturanSekolah::class);
        $settings->jenjang = Jenjang::Sma;
        $settings->save();
        $this->tahunAjaran = $this->tahunAjaran('2026/2027', aktif: true);
        $this->actingAs(User::factory()->operator()->create(['name' => 'Operator', 'username' => 'operator']));
    }

    public function test_the_list_shows_the_rombel_of_the_active_school_year_and_filters_by_rombel_and_tingkat(): void
    {
        $x1 = $this->rombel(Tingkat::X, 1);
        $xi1 = $this->rombel(Tingkat::XI, 1);
        $ani = $this->siswa('Ani');
        $budi = $this->siswa('Budi');
        $citra = $this->siswa('Citra');
        PenempatanRombel::factory()->for($ani)->for($x1)->create();
        PenempatanRombel::factory()->for($budi)->for($xi1)->create();
        PenempatanRombel::factory()->for($citra)->for($this->rombel(Tingkat::X, 9, $this->tahunAjaran('2025/2026')))->create();

        Livewire::test(ListSiswas::class)
            ->assertTableColumnStateSet('rombel', 'X-1', $ani)
            ->assertTableColumnStateSet('rombel', 'XI-1', $budi)
            ->assertTableColumnStateSet('rombel', null, $citra)
            ->filterTable('rombel', $x1->id)
            ->assertCanSeeTableRecords([$ani])
            ->assertCanNotSeeTableRecords([$budi, $citra])
            ->resetTableFilters()
            ->filterTable('tingkat', Tingkat::X->value)
            ->assertCanSeeTableRecords([$ani])
            ->assertCanNotSeeTableRecords([$budi, $citra]);
    }

    public function test_placing_one_student_through_the_action(): void
    {
        $x1 = $this->rombel(Tingkat::X, 1);
        $budi = $this->siswa('Budi');

        Livewire::test(ListSiswas::class)
            ->mountAction(TestAction::make('tempatkan')->table($budi))
            ->fillForm(['rombel_id' => $x1->id])
            ->assertFormFieldHidden('konfirmasi_pindah');

        Livewire::test(ListSiswas::class)
            ->callAction(TestAction::make('tempatkan')->table($budi), data: ['rombel_id' => $x1->id])
            ->assertHasNoFormErrors()
            ->assertNotified('Budi ditempatkan di X-1');

        $this->assertSame($x1->id, PenempatanRombel::query()->where('siswa_id', $budi->id)->value('rombel_id'));
    }

    public function test_choosing_another_rombel_of_the_same_year_becomes_a_move_that_must_be_confirmed(): void
    {
        $x1 = $this->rombel(Tingkat::X, 1);
        $x2 = $this->rombel(Tingkat::X, 2);
        $budi = $this->siswa('Budi');
        PenempatanRombel::factory()->for($budi)->for($x1)->create();

        Livewire::test(ListSiswas::class)
            ->mountAction(TestAction::make('tempatkan')->table($budi))
            ->fillForm(['rombel_id' => $x2->id])
            ->assertFormFieldExists('konfirmasi_pindah', fn (Checkbox $field): bool => $field->isVisible()
                && $field->getLabel() === 'Ya, pindahkan Budi dari X-1 ke X-2.')
            ->callMountedAction()
            ->assertHasFormErrors(['konfirmasi_pindah']);

        $this->assertSame($x1->id, PenempatanRombel::query()->where('siswa_id', $budi->id)->value('rombel_id'));

        Livewire::test(ListSiswas::class)
            ->callAction(TestAction::make('tempatkan')->table($budi), data: ['rombel_id' => $x2->id, 'konfirmasi_pindah' => true])
            ->assertHasNoFormErrors()
            ->assertNotified('Budi dipindah dari X-1 ke X-2');

        $this->assertSame($x2->id, PenempatanRombel::query()->where('siswa_id', $budi->id)->value('rombel_id'));
        $this->assertSame(1, Activity::query()->where('event', 'siswa_dipindah_rombel')->count());
    }

    public function test_only_rombel_of_the_active_and_later_school_years_are_offered(): void
    {
        $this->rombel(Tingkat::X, 1);
        $this->rombel(Tingkat::X, 7, $this->tahunAjaran('2025/2026'));
        $this->rombel(Tingkat::XI, 1, $this->tahunAjaran('2027/2028'));
        $budi = $this->siswa('Budi');

        Livewire::test(ListSiswas::class)
            ->mountAction(TestAction::make('tempatkan')->table($budi))
            ->assertFormFieldExists('rombel_id', fn (Select $field): bool => array_map(
                fn (array $grup): array => array_values($grup),
                $field->getOptions(),
            ) === ['2026/2027' => ['X-1'], '2027/2028' => ['XI-1']]);
    }

    public function test_placing_several_students_reports_the_ones_that_were_skipped(): void
    {
        $x1 = $this->rombel(Tingkat::X, 1, kapasitas: 2);
        $x2 = $this->rombel(Tingkat::X, 2);
        $dodi = $this->siswa('Dodi', nis: '20261004');
        $citra = $this->siswa('Citra', nis: '20261003');
        $budi = $this->siswa('Budi', nis: '20261002');
        $ani = $this->siswa('Ani', nis: '20261001');
        PenempatanRombel::factory()->for($budi)->for($x2)->create();

        Livewire::test(ListSiswas::class)
            ->selectTableRecords([$dodi->id, $citra->id, $budi->id, $ani->id])
            ->callAction(TestAction::make('tempatkanBanyak')->table()->bulk(), data: ['rombel_id' => $x1->id])
            ->assertNotified('2 dari 4 siswa ditempatkan di X-1');

        $this->assertEqualsCanonicalizing([$ani->id, $citra->id], PenempatanRombel::query()->where('rombel_id', $x1->id)->pluck('siswa_id')->all());
        $this->assertSame($x2->id, PenempatanRombel::query()->where('siswa_id', $budi->id)->value('rombel_id'));
        $this->assertFalse(PenempatanRombel::query()->where('siswa_id', $dodi->id)->exists());
    }

    public function test_deleting_a_mistaken_placement_through_the_action(): void
    {
        $x1 = $this->rombel(Tingkat::X, 1);
        $budi = $this->siswa('Budi');
        $penempatan = PenempatanRombel::factory()->for($budi)->for($x1)->create();

        Livewire::test(ListSiswas::class)
            ->callAction(TestAction::make('hapusPenempatan')->table($budi), data: ['penempatan_id' => $penempatan->id])
            ->assertNotified('Penempatan Budi di X-1 dihapus');

        $this->assertSame(0, PenempatanRombel::query()->count());
    }

    public function test_a_student_who_left_has_no_placement_actions(): void
    {
        $budi = $this->siswa('Budi', StatusSiswa::Lulus);

        Livewire::test(ListSiswas::class)
            ->assertActionHidden(TestAction::make('tempatkan')->table($budi))
            ->assertActionHidden(TestAction::make('hapusPenempatan')->table($budi));
    }

    public function test_coming_back_into_a_full_rombel_shows_the_reason(): void
    {
        $x1 = $this->rombel(Tingkat::X, 1, kapasitas: 1);
        $budi = $this->siswa('Budi', StatusSiswa::Keluar);
        PenempatanRombel::factory()->for($budi)->for($x1)->create();
        PenempatanRombel::factory()->for($this->siswa('Ani'))->for($x1)->create();

        Livewire::test(ListSiswas::class)
            ->callAction(TestAction::make('aktifkanKembali')->table($budi))
            ->assertNotified('Rombel X-1 sudah penuh (1 siswa aktif). Naikkan kapasitasnya dulu.');

        $this->assertSame(StatusSiswa::Keluar, $budi->fresh()->status);
    }

    public function test_the_rombel_page_lists_its_members_read_only_and_counts_active_ones_against_the_capacity(): void
    {
        $x1 = $this->rombel(Tingkat::X, 1, kapasitas: 32);
        $ani = PenempatanRombel::factory()->for($this->siswa('Ani'))->for($x1)->create();
        $budi = PenempatanRombel::factory()->for($this->siswa('Budi', StatusSiswa::MutasiKeluar))->for($x1)->create();
        $citra = PenempatanRombel::factory()->for($this->siswa('Citra'))->for($x1)->create();
        $lain = PenempatanRombel::factory()->for($this->siswa('Dodi'))->for($this->rombel(Tingkat::X, 2))->create();

        Livewire::test(AnggotaRelationManager::class, ['ownerRecord' => $x1, 'pageClass' => EditRombel::class])
            ->assertSee('Anggota (2 dari 32)')
            ->assertCanSeeTableRecords([$ani, $budi, $citra], inOrder: true)
            ->assertCanNotSeeTableRecords([$lain])
            ->assertTableColumnFormattedStateSet('siswa.status', 'Mutasi keluar', $budi)
            ->assertActionDoesNotExist(TestAction::make('create')->table())
            ->assertActionDoesNotExist(TestAction::make('delete')->table($ani));
    }

    public function test_a_rombel_without_capacity_says_so(): void
    {
        $x1 = $this->rombel(Tingkat::X, 1);
        PenempatanRombel::factory()->for($this->siswa('Ani'))->for($x1)->create();

        $this->assertSame('Anggota (1, tanpa batas)', AnggotaRelationManager::judul($x1));
    }

    public function test_the_member_list_needs_the_right_to_view_students(): void
    {
        $x1 = $this->rombel(Tingkat::X, 1);
        $user = User::factory()->create();
        $user->givePermissionTo('View:Rombel');

        $this->actingAs($user);
        $this->assertFalse(AnggotaRelationManager::canViewForRecord($x1, EditRombel::class));

        $user->givePermissionTo('ViewAny:Siswa');
        $this->assertTrue(AnggotaRelationManager::canViewForRecord($x1, EditRombel::class));
    }

    public function test_deleting_a_rombel_with_members_shows_the_reason(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $x1 = $this->rombel(Tingkat::X, 1);
        PenempatanRombel::factory()->for($this->siswa('Ani'))->for($x1)->create();

        Livewire::test(ListRombels::class)
            ->callAction(TestAction::make('hapus')->table($x1))
            ->assertNotified('Rombel X-1 sudah dipakai oleh 1 siswa dan tidak bisa dihapus.');

        $this->assertNotNull($x1->fresh());
    }

    private function tahunAjaran(string $nama, bool $aktif = false): TahunAjaran
    {
        $tahun = (int) substr($nama, 0, 4);

        return TahunAjaran::factory()->create([
            'nama' => $nama,
            'tanggal_mulai' => "{$tahun}-07-13",
            'tanggal_selesai' => ($tahun + 1).'-06-26',
            'status_aktif' => $aktif,
        ]);
    }

    private function rombel(Tingkat $tingkat, int $nomor, ?TahunAjaran $tahunAjaran = null, ?int $kapasitas = null): Rombel
    {
        return Rombel::factory()->for($tahunAjaran ?? $this->tahunAjaran)->create(['tingkat' => $tingkat, 'nomor' => $nomor, 'kapasitas' => $kapasitas]);
    }

    private function siswa(string $nama, StatusSiswa $status = StatusSiswa::Aktif, ?string $nis = null): Siswa
    {
        return Siswa::factory()
            ->for(User::factory()->state(['name' => $nama]))
            ->create(['status' => $status, ...($nis === null ? [] : ['nis' => $nis])]);
    }
}
