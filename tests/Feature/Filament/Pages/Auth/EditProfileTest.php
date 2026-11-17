<?php

namespace Tests\Feature\Filament\Pages\Auth;

use App\Domains\Foundation\Enums\Agama;
use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Models\User;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Users\Schemas\UserForm;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

    public function test_profile_shows_the_nik_masked_and_locked_biodata_read_only(): void
    {
        $this->actingAs($this->operatorWithBiodata());

        Livewire::test(EditProfile::class)
            ->assertSee('7404********0001')
            ->assertDontSee('7404012345670001')
            ->assertSee(UserForm::KETERANGAN_NIK)
            ->assertSee('Laki-laki')
            ->assertSee('Kendari, 17 Mei 1990')
            ->assertSee('Islam')
            ->assertFormFieldDoesNotExist('nik')
            ->assertFormFieldDoesNotExist('jenis_kelamin')
            ->assertFormFieldDoesNotExist('tempat_lahir')
            ->assertFormFieldDoesNotExist('tanggal_lahir')
            ->assertFormFieldDoesNotExist('agama')
            ->assertFormFieldExists('no_hp')
            ->assertFormFieldExists('alamat')
            ->assertFormFieldExists('foto');
    }

    public function test_user_changes_own_phone_address_and_photo(): void
    {
        Storage::fake('local');
        $operator = $this->operatorWithBiodata();
        $this->actingAs($operator);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'no_hp' => '+62 812 9999 8888',
                'alamat' => 'Jl. Baru',
                'foto' => UploadedFile::fake()->image('saya.jpg'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $operator = $operator->fresh();
        $this->assertSame('6281299998888', $operator->no_hp);
        $this->assertSame('Jl. Baru', $operator->alamat);
        $this->assertStringStartsWith('foto-pengguna/', (string) $operator->foto);
        Storage::disk('local')->assertExists((string) $operator->foto);
    }

    public function test_a_forged_request_cannot_change_the_nik_or_locked_biodata(): void
    {
        $operator = $this->operatorWithBiodata();
        $this->actingAs($operator);

        Livewire::test(EditProfile::class)
            ->set('data.nik', '7404012345679999')
            ->set('data.hapus_nik', true)
            ->set('data.jenis_kelamin', 'perempuan')
            ->set('data.tempat_lahir', 'Lain')
            ->set('data.tanggal_lahir', '2000-01-01')
            ->set('data.agama', 'Hindu')
            ->call('save')
            ->assertHasNoFormErrors();

        $operator = $operator->fresh();
        $this->assertSame('7404012345670001', $operator->nik);
        $this->assertSame(JenisKelamin::LakiLaki, $operator->jenis_kelamin);
        $this->assertSame('Kendari', $operator->tempat_lahir);
        $this->assertSame('1990-05-17', $operator->tanggal_lahir?->toDateString());
        $this->assertSame(Agama::Islam, $operator->agama);
    }

    public function test_an_invalid_phone_number_shows_on_its_field(): void
    {
        $operator = $this->operatorWithBiodata();
        $this->actingAs($operator);

        Livewire::test(EditProfile::class)
            ->fillForm(['no_hp' => '123'])
            ->call('save')
            ->assertHasFormErrors(['no_hp']);

        $this->assertSame('6281234567890', $operator->fresh()->no_hp);
    }

    private function operatorWithBiodata(): User
    {
        $operator = User::factory()->operator()->create([
            'jenis_kelamin' => 'laki-laki',
            'tempat_lahir' => 'Kendari',
            'tanggal_lahir' => '1990-05-17',
            'agama' => 'Islam',
            'no_hp' => '6281234567890',
        ]);
        $operator->isiNik('7404012345670001');
        $operator->save();

        return $operator;
    }
}
