<?php

namespace Tests\Feature\Auth;

use App\Domains\Foundation\Models\User;
use App\Filament\Pages\Auth\Login;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_logs_in_with_correct_username_and_password(): void
    {
        $user = User::factory()->operator()->create(['username' => 'budi']);

        $this->attemptLogin('budi', 'password')
            ->assertHasNoErrors()
            ->assertRedirect(Filament::getUrl());

        $this->assertAuthenticatedAs($user);
    }

    public function test_logs_in_with_username_in_different_letter_case(): void
    {
        $user = User::factory()->operator()->create(['username' => 'budi']);

        $this->attemptLogin('  BuDi ', 'password')->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_rejects_login_with_email_instead_of_username(): void
    {
        User::factory()->operator()->create(['username' => 'budi', 'email' => 'budi@sekolah.test']);

        $this->attemptLogin('budi@sekolah.test', 'password')
            ->assertHasErrors(['data.username' => __('filament-panels::auth/pages/login.messages.failed')]);

        $this->assertGuest();
    }

    public function test_rejects_login_for_inactive_user(): void
    {
        User::factory()->operator()->nonaktif()->create(['username' => 'budi']);

        $this->attemptLogin('budi', 'password')
            ->assertHasErrors(['data.username' => __('filament-panels::auth/pages/login.messages.failed')]);

        $this->assertGuest();
    }

    public function test_forbids_signed_in_inactive_user_from_panel_with_403(): void
    {
        $user = User::factory()->operator()->nonaktif()->create();

        $this->actingAs($user)
            ->get(Filament::getUrl())
            ->assertForbidden();
    }

    public function test_rejects_login_for_user_without_role(): void
    {
        User::factory()->create(['username' => 'budi']);

        $this->attemptLogin('budi', 'password')
            ->assertHasErrors(['data.username' => __('filament-panels::auth/pages/login.messages.failed')]);

        $this->assertGuest();
    }

    public function test_forbids_signed_in_user_without_role_from_panel_with_403(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(Filament::getUrl())
            ->assertForbidden();
    }

    public function test_stores_username_trimmed_and_lowercased(): void
    {
        $user = User::factory()->create(['username' => '  Budi.Santoso ']);

        $this->assertSame('budi.santoso', $user->fresh()->username);
    }

    public function test_rejects_duplicate_username_regardless_of_letter_case(): void
    {
        User::factory()->create(['username' => 'budi']);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create(['username' => 'BUDI']);
    }

    private function attemptLogin(string $username, string $password): Testable
    {
        return Livewire::test(Login::class)
            ->fillForm([
                'username' => $username,
                'password' => $password,
            ])
            ->call('authenticate');
    }
}
