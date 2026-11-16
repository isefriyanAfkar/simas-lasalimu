<?php

namespace Tests\Feature\Filament\Resources\MataPelajarans;

use App\Domains\Academic\Models\MataPelajaran;
use App\Domains\Foundation\Models\User;
use App\Filament\Resources\MataPelajarans\MataPelajaranResource;
use App\Filament\Resources\MataPelajarans\Pages\CreateMataPelajaran;
use App\Filament\Resources\MataPelajarans\Pages\EditMataPelajaran;
use App\Filament\Resources\MataPelajarans\Pages\ListMataPelajarans;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MataPelajaranResourceTest extends TestCase
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
            ->get(MataPelajaranResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Mata Pelajaran');
    }

    public function test_user_without_role_cannot_open_the_list(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(MataPelajaranResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_list_is_ordered_by_urutan_then_name(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $sejarah = MataPelajaran::factory()->create(['kode' => 'SEJ', 'nama' => 'Sejarah', 'urutan' => null]);
        $matematika = MataPelajaran::factory()->create(['kode' => 'MTK', 'nama' => 'Matematika', 'urutan' => 2]);
        $agama = MataPelajaran::factory()->create(['kode' => 'PAI', 'nama' => 'Pendidikan Agama', 'urutan' => 1]);

        Livewire::test(ListMataPelajarans::class)
            ->assertCanSeeTableRecords([$agama, $matematika, $sejarah], inOrder: true);
    }

    public function test_list_can_be_searched_by_code_and_by_name(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $matematika = MataPelajaran::factory()->create(['kode' => 'MTK', 'nama' => 'Matematika']);
        $biologi = MataPelajaran::factory()->create(['kode' => 'BIOL', 'nama' => 'Biologi']);

        Livewire::test(ListMataPelajarans::class)
            ->searchTable('MTK')
            ->assertCanSeeTableRecords([$matematika])
            ->assertCanNotSeeTableRecords([$biologi])
            ->searchTable('Biologi')
            ->assertCanSeeTableRecords([$biologi])
            ->assertCanNotSeeTableRecords([$matematika]);
    }

    public function test_list_can_be_filtered_by_status(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $aktif = MataPelajaran::factory()->create();
        $nonaktif = MataPelajaran::factory()->nonaktif()->create();

        Livewire::test(ListMataPelajarans::class)
            ->assertCanSeeTableRecords([$aktif, $nonaktif])
            ->filterTable('status_aktif', true)
            ->assertCanSeeTableRecords([$aktif])
            ->assertCanNotSeeTableRecords([$nonaktif])
            ->filterTable('status_aktif', false)
            ->assertCanSeeTableRecords([$nonaktif])
            ->assertCanNotSeeTableRecords([$aktif]);
    }

    public function test_operator_creates_a_subject_through_the_form(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(CreateMataPelajaran::class)
            ->fillForm(['kode' => 'mtk', 'nama' => 'Matematika', 'urutan' => 2])
            ->call('create')
            ->assertHasNoFormErrors();

        $mataPelajaran = MataPelajaran::query()->sole();
        $this->assertSame(['MTK', 'Matematika', 2, true], array_values($mataPelajaran->only(['kode', 'nama', 'urutan', 'status_aktif'])));
    }

    public function test_form_offers_no_status_field(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(CreateMataPelajaran::class)
            ->assertFormFieldExists('kode')
            ->assertFormFieldExists('urutan')
            ->assertFormFieldDoesNotExist('status_aktif');
    }

    public function test_a_taken_code_is_shown_on_the_code_field(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        MataPelajaran::factory()->create(['kode' => 'MTK']);

        Livewire::test(CreateMataPelajaran::class)
            ->fillForm(['kode' => ' mtk ', 'nama' => 'Matematika Lain'])
            ->call('create')
            ->assertHasFormErrors(['kode']);

        $this->assertSame(1, MataPelajaran::query()->count());
    }

    public function test_operator_updates_a_subject_through_the_form(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $mataPelajaran = MataPelajaran::factory()->create(['kode' => 'MTK', 'nama' => 'Matematika']);

        Livewire::test(EditMataPelajaran::class, ['record' => $mataPelajaran->getRouteKey()])
            ->fillForm(['nama' => 'Matematika Wajib', 'urutan' => 1])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Matematika Wajib', $mataPelajaran->fresh()->nama);
        $this->assertSame(1, $mataPelajaran->fresh()->urutan);
    }

    public function test_operator_deactivates_and_activates_a_subject(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $mataPelajaran = MataPelajaran::factory()->create(['kode' => 'MTK', 'nama' => 'Matematika']);

        Livewire::test(ListMataPelajarans::class)
            ->assertActionHidden(TestAction::make('aktifkan')->table($mataPelajaran))
            ->callAction(TestAction::make('nonaktifkan')->table($mataPelajaran))
            ->assertNotified('Mata pelajaran Matematika dinonaktifkan');

        $this->assertFalse($mataPelajaran->fresh()->status_aktif);

        Livewire::test(ListMataPelajarans::class)
            ->assertActionHidden(TestAction::make('nonaktifkan')->table($mataPelajaran->fresh()))
            ->callAction(TestAction::make('aktifkan')->table($mataPelajaran->fresh()))
            ->assertNotified('Mata pelajaran Matematika diaktifkan');

        $this->assertTrue($mataPelajaran->fresh()->status_aktif);
    }

    public function test_offers_no_delete_action_even_to_super_admin(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $mataPelajaran = MataPelajaran::factory()->create();

        Livewire::test(ListMataPelajarans::class)
            ->assertActionDoesNotExist(TestAction::make('delete')->table($mataPelajaran))
            ->assertActionDoesNotExist(TestAction::make('hapus')->table($mataPelajaran))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

        Livewire::test(EditMataPelajaran::class, ['record' => $mataPelajaran->getRouteKey()])
            ->assertActionDoesNotExist('delete');
    }
}
