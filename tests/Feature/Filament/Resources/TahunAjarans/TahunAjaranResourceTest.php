<?php

namespace Tests\Feature\Filament\Resources\TahunAjarans;

use App\Domains\Academic\Enums\JenisSemester;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\Semester;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\TahunAjaranService;
use App\Domains\Foundation\Models\User;
use App\Filament\Resources\TahunAjarans\Pages\CreateTahunAjaran;
use App\Filament\Resources\TahunAjarans\Pages\EditTahunAjaran;
use App\Filament\Resources\TahunAjarans\Pages\ListTahunAjarans;
use App\Filament\Resources\TahunAjarans\RelationManagers\SemesterRelationManager;
use App\Filament\Resources\TahunAjarans\TahunAjaranResource;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TahunAjaranResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_operator_opens_the_list(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->get(TahunAjaranResource::getUrl('index'))
            ->assertOk();
    }

    public function test_operator_creates_a_school_year_through_the_form(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(CreateTahunAjaran::class)
            ->fillForm(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26'])
            ->call('create')
            ->assertHasNoFormErrors();

        $tahunAjaran = TahunAjaran::query()->where('nama', '2026/2027')->sole();
        $this->assertSame(2, $tahunAjaran->semester()->count());
    }

    public function test_service_rules_show_up_on_the_form_fields(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(CreateTahunAjaran::class)
            ->fillForm(['nama' => '2026/2028', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26'])
            ->call('create')
            ->assertHasFormErrors(['nama']);

        $this->assertSame(0, TahunAjaran::query()->count());
    }

    public function test_operator_updates_a_school_year_through_the_form(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $tahunAjaran = $this->schoolYear();

        Livewire::test(EditTahunAjaran::class, ['record' => $tahunAjaran->getRouteKey()])
            ->fillForm(['tanggal_selesai' => '2027-06-30'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2027-06-30', $tahunAjaran->fresh()->tanggal_selesai->toDateString());
    }

    public function test_operator_activates_a_school_year_but_sees_no_delete_action(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $tahunAjaran = $this->schoolYear();

        Livewire::test(ListTahunAjarans::class)
            ->assertActionHidden(TestAction::make('hapus')->table($tahunAjaran))
            ->callAction(TestAction::make('aktifkan')->table($tahunAjaran))
            ->assertNotified('Tahun ajaran 2026/2027 diaktifkan');

        $this->assertTrue($tahunAjaran->fresh()->status_aktif);
    }

    public function test_super_admin_deletes_an_inactive_school_year_with_its_semesters(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $tahunAjaran = $this->schoolYear();

        Livewire::test(ListTahunAjarans::class)
            ->callAction(TestAction::make('hapus')->table($tahunAjaran));

        $this->assertSame(0, TahunAjaran::query()->count());
        $this->assertSame(0, Semester::query()->count());
    }

    public function test_deleting_a_school_year_used_by_rombel_shows_the_refusal(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $tahunAjaran = $this->schoolYear();
        Rombel::factory()->for($tahunAjaran)->create(['nomor' => 1]);

        Livewire::test(ListTahunAjarans::class)
            ->callAction(TestAction::make('hapus')->table($tahunAjaran))
            ->assertNotified('Tahun ajaran 2026/2027 sudah dipakai oleh 1 rombel. Hapus rombelnya dulu.');

        $this->assertNotNull($tahunAjaran->fresh());
    }

    public function test_delete_action_is_hidden_for_the_active_school_year(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin);
        $tahunAjaran = $this->schoolYear();
        app(TahunAjaranService::class)->activate($superAdmin, $tahunAjaran);

        Livewire::test(ListTahunAjarans::class)
            ->assertActionHidden(TestAction::make('hapus')->table($tahunAjaran->fresh()))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());
    }

    public function test_user_without_role_cannot_open_the_list(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(TahunAjaranResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_semester_table_offers_no_add_or_remove_action(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $tahunAjaran = $this->schoolYear();

        Livewire::test(SemesterRelationManager::class, ['ownerRecord' => $tahunAjaran, 'pageClass' => EditTahunAjaran::class])
            ->assertOk()
            ->assertCanSeeTableRecords($tahunAjaran->semester)
            ->assertActionDoesNotExist(TestAction::make('create')->table())
            ->assertActionDoesNotExist(TestAction::make('delete')->table($tahunAjaran->semesterGanjil))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());
    }

    public function test_operator_changes_semester_dates_and_activates_a_semester(): void
    {
        $operator = User::factory()->operator()->create();
        $this->actingAs($operator);
        $this->travelTo('2026-09-15');
        $tahunAjaran = $this->schoolYear();
        app(TahunAjaranService::class)->activate($operator, $tahunAjaran);

        Livewire::test(SemesterRelationManager::class, ['ownerRecord' => $tahunAjaran, 'pageClass' => EditTahunAjaran::class])
            ->callAction(TestAction::make('ubahTanggal')->table($tahunAjaran->semesterGanjil), data: [
                'tanggal_mulai' => '2026-07-20',
                'tanggal_selesai' => '2026-12-19',
            ])
            ->assertHasNoFormErrors()
            ->callAction(TestAction::make('aktifkan')->table($tahunAjaran->semesterGenap));

        $this->assertSame('2026-12-19', $tahunAjaran->semesterGanjil->fresh()->tanggal_selesai->toDateString());
        $this->assertSame(JenisSemester::Genap, Semester::query()->where('status_aktif', true)->sole()->jenis);
    }

    public function test_semester_activate_action_is_hidden_while_the_school_year_is_inactive(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $tahunAjaran = $this->schoolYear();

        Livewire::test(SemesterRelationManager::class, ['ownerRecord' => $tahunAjaran, 'pageClass' => EditTahunAjaran::class])
            ->assertActionHidden(TestAction::make('aktifkan')->table($tahunAjaran->semesterGanjil))
            ->assertActionHidden(TestAction::make('aktifkan')->table($tahunAjaran->semesterGenap));
    }

    public function test_semester_date_rule_violation_is_shown_on_the_field(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $tahunAjaran = $this->schoolYear();

        Livewire::test(SemesterRelationManager::class, ['ownerRecord' => $tahunAjaran, 'pageClass' => EditTahunAjaran::class])
            ->callAction(TestAction::make('ubahTanggal')->table($tahunAjaran->semesterGanjil), data: [
                'tanggal_mulai' => '2026-07-13',
                'tanggal_selesai' => '2027-01-05',
            ])
            ->assertHasFormErrors(['tanggal_selesai']);

        $this->assertSame('2026-12-31', $tahunAjaran->semesterGanjil->fresh()->tanggal_selesai->toDateString());
    }

    private function schoolYear(): TahunAjaran
    {
        return app(TahunAjaranService::class)->create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-26']);
    }
}
