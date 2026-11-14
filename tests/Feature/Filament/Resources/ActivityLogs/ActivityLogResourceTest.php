<?php

namespace Tests\Feature\Filament\Resources\ActivityLogs;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_operator_gets_403_on_the_activity_log(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->get(ActivityLogResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_operator_with_every_user_permission_still_gets_403(): void
    {
        $operator = User::factory()->operator()->create();
        $operator->givePermissionTo(PeranDanIzinSeeder::USER_PERMISSIONS);

        $this->actingAs($operator)
            ->get(ActivityLogResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_super_admin_sees_actor_action_object_and_changes(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['name' => 'Kepala TU']);
        $operator = User::factory()->operator()->create(['username' => 'siti']);
        app(UserService::class)->deactivate($superAdmin, $operator);
        $activity = Activity::query()->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$activity])
            ->assertTableColumnStateSet('causer.name', 'Kepala TU', $activity)
            ->assertTableColumnFormattedStateSet('event', 'Pengguna dinonaktifkan', $activity)
            ->assertTableColumnStateSet('objek', 'siti', $activity)
            ->assertTableColumnStateSet('rincian', 'status_aktif: aktif → nonaktif', $activity);
    }

    public function test_entry_without_actor_is_shown_as_sistem(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $activity = activity('pengguna')->causedByAnonymous()->event('dibuat')->log('Pengguna dibuat');

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->assertTableColumnStateSet('causer.name', 'Sistem', $activity);
    }

    public function test_filters_by_actor(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $otherSuperAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();
        app(UserService::class)->deactivate($superAdmin, $operator);
        app(UserService::class)->activate($otherSuperAdmin, $operator);
        $bySuperAdmin = Activity::query()->where('event', 'dinonaktifkan')->sole();
        $byOther = Activity::query()->where('event', 'diaktifkan')->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('pelaku', $superAdmin->id)
            ->assertCanSeeTableRecords([$bySuperAdmin])
            ->assertCanNotSeeTableRecords([$byOther]);
    }

    public function test_filters_by_date_range(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();

        $this->travelTo('2026-11-01 09:00:00');
        app(UserService::class)->deactivate($superAdmin, $operator);
        $this->travelTo('2026-11-10 09:00:00');
        app(UserService::class)->activate($superAdmin, $operator);
        $this->travelBack();

        $early = Activity::query()->where('event', 'dinonaktifkan')->sole();
        $late = Activity::query()->where('event', 'diaktifkan')->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('waktu', ['dari' => '2026-11-05', 'sampai' => '2026-11-12'])
            ->assertCanSeeTableRecords([$late])
            ->assertCanNotSeeTableRecords([$early]);
    }

    public function test_is_read_only_even_for_super_admin(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();
        app(UserService::class)->deactivate($superAdmin, $operator);
        $activity = Activity::query()->sole();

        $this->actingAs($superAdmin);

        Livewire::test(ListActivityLogs::class)
            ->assertActionDoesNotExist('create')
            ->assertActionDoesNotExist(TestAction::make('edit')->table($activity))
            ->assertActionDoesNotExist(TestAction::make('delete')->table($activity))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

        $this->assertSame(['index'], array_keys(ActivityLogResource::getPages()));
    }
}
