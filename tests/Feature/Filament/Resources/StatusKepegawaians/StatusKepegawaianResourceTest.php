<?php

namespace Tests\Feature\Filament\Resources\StatusKepegawaians;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\StatusKepegawaian;
use App\Filament\Resources\StatusKepegawaians\Pages\CreateStatusKepegawaian;
use App\Filament\Resources\StatusKepegawaians\Pages\EditStatusKepegawaian;
use App\Filament\Resources\StatusKepegawaians\Pages\ListStatusKepegawaians;
use App\Filament\Resources\StatusKepegawaians\StatusKepegawaianResource;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StatusKepegawaianResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_super_admin_opens_the_list_ordered_by_urutan(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(StatusKepegawaianResource::getUrl('index'))
            ->assertOk()
            ->assertSeeInOrder(['Status Kepegawaian', 'PNS', 'PPPK', 'GTT', 'PTT', 'GTBPNS']);
    }

    public function test_operator_gets_403_on_every_page(): void
    {
        $status = StatusKepegawaian::query()->firstOrFail();
        $this->actingAs(User::factory()->operator()->create());

        $this->get(StatusKepegawaianResource::getUrl('index'))->assertForbidden();
        $this->get(StatusKepegawaianResource::getUrl('create'))->assertForbidden();
        $this->get(StatusKepegawaianResource::getUrl('edit', ['record' => $status]))->assertForbidden();
    }

    public function test_user_without_role_gets_403_on_the_list(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(StatusKepegawaianResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_super_admin_creates_a_status_through_the_form(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CreateStatusKepegawaian::class)
            ->fillForm(['nama' => ' Honorer Daerah ', 'urutan' => 6])
            ->call('create')
            ->assertHasNoFormErrors();

        $status = StatusKepegawaian::query()->where('nama', 'Honorer Daerah')->sole();
        $this->assertSame([6, true], array_values($status->only(['urutan', 'status_aktif'])));
    }

    public function test_a_taken_name_is_shown_on_the_name_field(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CreateStatusKepegawaian::class)
            ->fillForm(['nama' => 'pns'])
            ->call('create')
            ->assertHasFormErrors(['nama']);

        $this->assertSame(5, StatusKepegawaian::query()->count());
    }

    public function test_form_offers_no_status_field(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CreateStatusKepegawaian::class)
            ->assertFormFieldExists('nama')
            ->assertFormFieldExists('urutan')
            ->assertFormFieldDoesNotExist('status_aktif');
    }

    public function test_super_admin_updates_a_status_through_the_form(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $gtt = StatusKepegawaian::query()->where('nama', 'GTT')->sole();

        Livewire::test(EditStatusKepegawaian::class, ['record' => $gtt->getRouteKey()])
            ->fillForm(['nama' => 'Guru Tidak Tetap', 'urutan' => 9])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Guru Tidak Tetap', 9], array_values($gtt->fresh()->only(['nama', 'urutan'])));
    }

    public function test_super_admin_deactivates_and_activates_a_status(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $gtt = StatusKepegawaian::query()->where('nama', 'GTT')->sole();

        Livewire::test(ListStatusKepegawaians::class)
            ->assertActionHidden(TestAction::make('aktifkan')->table($gtt))
            ->callAction(TestAction::make('nonaktifkan')->table($gtt))
            ->assertNotified('Status kepegawaian GTT dinonaktifkan');

        $this->assertFalse($gtt->fresh()->status_aktif);

        Livewire::test(ListStatusKepegawaians::class)
            ->assertActionHidden(TestAction::make('nonaktifkan')->table($gtt->fresh()))
            ->callAction(TestAction::make('aktifkan')->table($gtt->fresh()))
            ->assertNotified('Status kepegawaian GTT diaktifkan');

        $this->assertTrue($gtt->fresh()->status_aktif);
    }

    public function test_list_can_be_searched_and_filtered_by_status(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $pns = StatusKepegawaian::query()->where('nama', 'PNS')->sole();
        $nonaktif = StatusKepegawaian::factory()->nonaktif()->create(['nama' => 'Honorer']);

        Livewire::test(ListStatusKepegawaians::class)
            ->searchTable('Honorer')
            ->assertCanSeeTableRecords([$nonaktif])
            ->assertCanNotSeeTableRecords([$pns])
            ->searchTable('')
            ->filterTable('status_aktif', false)
            ->assertCanSeeTableRecords([$nonaktif])
            ->assertCanNotSeeTableRecords([$pns]);
    }

    public function test_offers_no_delete_action_even_to_super_admin(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $status = StatusKepegawaian::query()->firstOrFail();

        Livewire::test(ListStatusKepegawaians::class)
            ->assertActionDoesNotExist(TestAction::make('delete')->table($status))
            ->assertActionDoesNotExist(TestAction::make('hapus')->table($status))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

        Livewire::test(EditStatusKepegawaian::class, ['record' => $status->getRouteKey()])
            ->assertActionDoesNotExist('delete');
    }
}
