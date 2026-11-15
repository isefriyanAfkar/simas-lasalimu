<?php

namespace Tests\Feature\Console\Commands;

use App\Domains\Foundation\Models\User;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class BuatSuperAdminTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_creates_an_active_super_admin_and_logs_it_as_system(): void
    {
        $this->runCommand('Kepala TU', '  KepalaTU ', 'rahasia-kuat-123', 'rahasia-kuat-123')
            ->expectsOutputToContain('Super admin kepalatu dibuat.')
            ->assertExitCode(0);

        $user = User::query()->where('username', 'kepalatu')->sole();
        $this->assertSame('Kepala TU', $user->name);
        $this->assertTrue($user->status_aktif);
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertTrue(Hash::check('rahasia-kuat-123', $user->password));

        $activity = Activity::query()->where('event', 'dibuat')->sole();
        $this->assertNull($activity->causer_id);
        $this->assertTrue($activity->subject->is($user));
        $this->assertStringNotContainsString('rahasia-kuat-123', json_encode($activity->getAttributes()));
    }

    public function test_works_when_another_super_admin_already_exists(): void
    {
        User::factory()->superAdmin()->create(['username' => 'superadmin']);

        $this->runCommand('Admin Kedua', 'admin2', 'rahasia-kuat-123', 'rahasia-kuat-123')
            ->assertExitCode(0);

        $this->assertSame(2, User::role('super_admin')->count());
    }

    public function test_rejects_a_username_that_is_already_taken(): void
    {
        User::factory()->operator()->create(['username' => 'budi']);

        $this->runCommand('Budi Lain', 'BUDI', 'rahasia-kuat-123', 'rahasia-kuat-123')
            ->expectsOutputToContain('Username sudah dipakai.')
            ->assertExitCode(1);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_rejects_a_password_confirmation_that_does_not_match(): void
    {
        $this->runCommand('Kepala TU', 'kepalatu', 'rahasia-kuat-123', 'rahasia-lain-456')
            ->expectsOutputToContain('Konfirmasi kata sandi tidak cocok.')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_rejects_a_password_shorter_than_the_application_rule(): void
    {
        $this->runCommand('Kepala TU', 'kepalatu', 'pendek', 'pendek')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_refuses_to_run_without_an_interactive_terminal(): void
    {
        $this->artisan('simas:buat-super-admin', ['--no-interaction' => true])
            ->expectsOutputToContain('Perintah ini harus dijalankan secara interaktif')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_trims_the_name(): void
    {
        $this->runCommand('  Kepala TU  ', 'kepalatu', 'rahasia-kuat-123', 'rahasia-kuat-123')
            ->assertExitCode(0);

        $this->assertSame('Kepala TU', User::query()->where('username', 'kepalatu')->sole()->name);
    }

    private function runCommand(string $name, string $username, string $password, string $confirmation): PendingCommand
    {
        return $this->artisan('simas:buat-super-admin')
            ->expectsQuestion('Nama', $name)
            ->expectsQuestion('Username', $username)
            ->expectsQuestion('Kata sandi', $password)
            ->expectsQuestion('Ulangi kata sandi', $confirmation);
    }
}
