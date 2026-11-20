<?php

namespace Tests\Feature\Domains\Foundation\Services;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\StatusKepegawaian;
use App\Domains\People\Services\PtkService;
use App\Domains\People\Services\SiswaService;
use App\Domains\People\Services\SiswaWaliService;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The random password of a student or parent account is never shown and
 * cannot be guessed whatever the hash cost, so it is hashed at the cheapest
 * cost (an import creates hundreds of them). A password a person chooses or
 * receives is hashed at the configured cost, and a cheap hash is raised to
 * it at the first login (Laravel's rehash on login).
 */
class BiayaHashKataSandiAcakTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);

        // The first factory user hashes UserFactory's shared password, kept
        // for the rest of the run, at the test cost; with the cost raised
        // first, every later test would find a hash above its cost.
        $this->operator = User::factory()->operator()->create();

        config(['hashing.bcrypt.rounds' => 12]);
        Hash::forgetDrivers();
    }

    public function test_a_new_student_account_gets_the_cheap_hash(): void
    {
        $siswa = app(SiswaService::class)->create($this->operator, [
            'name' => 'Budi',
            'nis' => '20261001',
            'jenis_kelamin' => 'laki-laki',
        ]);

        $this->assertSame(UserService::BIAYA_HASH_KATA_SANDI_ACAK, $this->cost($siswa->user->fresh()));
    }

    public function test_a_new_parent_account_gets_the_cheap_hash(): void
    {
        $siswaWali = app(SiswaWaliService::class)->tambahkan($this->operator, Siswa::factory()->create(), [
            'wali' => 'baru',
            'hubungan' => 'ayah',
            'name' => 'Ahmad',
        ]);

        $this->assertSame(UserService::BIAYA_HASH_KATA_SANDI_ACAK, $this->cost($siswaWali->waliMurid->user->fresh()));
    }

    public function test_a_new_staff_account_keeps_the_configured_cost(): void
    {
        $ptk = app(PtkService::class)->create($this->operator, [
            'akun' => 'baru',
            'name' => 'Hafida',
            'username' => 'hafida',
            'jenis_kelamin' => 'perempuan',
            'jenis_ptk' => 'guru',
            'status_kepegawaian_id' => StatusKepegawaian::query()->where('nama', 'PNS')->value('id'),
            'sertifikasi' => false,
        ]);

        $this->assertSame(12, $this->cost($ptk->user->fresh()));
    }

    public function test_a_password_set_by_an_admin_gets_the_configured_cost(): void
    {
        $siswa = Siswa::factory()->create();

        app(UserService::class)->resetPassword(User::factory()->superAdmin()->create(), $siswa->user, 'Rahasia#2026!');

        $this->assertSame(12, $this->cost($siswa->user->fresh()));
    }

    public function test_a_cheap_hash_is_raised_to_the_configured_cost_at_login(): void
    {
        $siswa = app(SiswaService::class)->create($this->operator, ['name' => 'Budi', 'nis' => '20261001', 'jenis_kelamin' => 'laki-laki']);
        $user = $siswa->user->fresh();
        $user->forceFill(['password' => Hash::make('Rahasia#2026!', ['rounds' => UserService::BIAYA_HASH_KATA_SANDI_ACAK])])->saveQuietly();
        $this->assertTrue(Hash::needsRehash($user->fresh()->getAuthPassword()));

        $this->assertTrue(Auth::attempt(['username' => '20261001', 'password' => 'Rahasia#2026!']));

        $this->assertSame(12, $this->cost($user->fresh()));
    }

    private function cost(User $user): int
    {
        return (int) Hash::info($user->getAuthPassword())['options']['cost'];
    }
}
