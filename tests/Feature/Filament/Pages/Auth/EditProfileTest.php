<?php

namespace Tests\Feature\Filament\Pages\Auth;

use App\Domains\Foundation\Models\User;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class EditProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_user_changes_own_password_with_correct_current_password(): void
    {
        $operator = User::factory()->operator()->create();
        $this->actingAs($operator);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'kata-sandi-baru-123',
                'passwordConfirmation' => 'kata-sandi-baru-123',
                'currentPassword' => 'password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('kata-sandi-baru-123', $operator->fresh()->password));
        $this->assertSame(1, Activity::query()->where('event', 'kata_sandi_diubah_sendiri')->count());
    }

    public function test_user_logs_in_with_the_password_set_on_the_profile_page(): void
    {
        $operator = User::factory()->operator()->create(['username' => 'budi']);
        $this->actingAs($operator);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'kata-sandi-baru-123',
                'passwordConfirmation' => 'kata-sandi-baru-123',
                'currentPassword' => 'password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        Filament::auth()->logout();

        Livewire::test(Login::class)
            ->fillForm(['username' => 'budi', 'password' => 'kata-sandi-baru-123'])
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($operator);
    }

    public function test_rejects_password_change_with_wrong_current_password(): void
    {
        $operator = User::factory()->operator()->create();
        $this->actingAs($operator);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'kata-sandi-baru-123',
                'passwordConfirmation' => 'kata-sandi-baru-123',
                'currentPassword' => 'salah-sekali',
            ])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->assertTrue(Hash::check('password', $operator->fresh()->password));
    }

    public function test_user_changes_own_name_without_current_password(): void
    {
        $operator = User::factory()->operator()->create(['name' => 'Nama Lama', 'email' => 'operator@sekolah.test']);
        $this->actingAs($operator);

        Livewire::test(EditProfile::class)
            ->fillForm(['name' => 'Nama Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nama Baru', $operator->fresh()->name);
        $this->assertSame(1, Activity::query()->where('event', 'profil_diubah')->count());
    }

    public function test_profile_form_has_no_username_field(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(EditProfile::class)
            ->assertFormFieldDoesNotExist('username')
            ->assertFormFieldDoesNotExist('email');
    }
}
