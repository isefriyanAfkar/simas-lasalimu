<?php

namespace Tests\Feature\Routes;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Models\Siswa;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * User photos live on the private disk and are served only through the
 * pengguna.foto route: signed-in, active, and allowed to see that user.
 */
class FotoPenggunaTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $image;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Storage::fake('local');
        $this->image = UploadedFile::fake()->image('budi.png')->getContent();
        Storage::disk('local')->put('foto-pengguna/budi.png', $this->image);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $user = $this->userWithPhoto();

        $this->get(route('pengguna.foto', $user))->assertRedirect('/admin/login');
    }

    public function test_the_photo_has_no_public_url(): void
    {
        $this->assertFileDoesNotExist(public_path('storage/foto-pengguna/budi.png'));

        $status = $this->get('/storage/foto-pengguna/budi.png')->getStatusCode();

        $this->assertContains($status, [403, 404]);
    }

    public function test_a_user_always_sees_their_own_photo(): void
    {
        $user = $this->userWithPhoto();

        $response = $this->actingAs($user)->get(route('pengguna.foto', $user));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($this->image, $response->streamedContent());
    }

    public function test_a_super_admin_sees_any_photo(): void
    {
        $user = $this->userWithPhoto();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('pengguna.foto', $user))
            ->assertOk();
    }

    public function test_someone_allowed_to_view_the_user_sees_the_photo(): void
    {
        $user = $this->userWithPhoto();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('View:User');

        $this->actingAs($viewer)->get(route('pengguna.foto', $user))->assertOk();
    }

    public function test_someone_not_allowed_to_view_the_user_gets_403(): void
    {
        $user = $this->userWithPhoto();
        $user->assignRole('operator');

        $this->actingAs(User::factory()->operator()->create())
            ->get(route('pengguna.foto', $user))
            ->assertForbidden();
    }

    /**
     * The Guru dan Staf form previews the photo of an account chosen to be
     * linked; operators cannot view users, but may link this account.
     */
    public function test_someone_who_may_link_an_account_sees_its_photo(): void
    {
        $user = $this->userWithPhoto();

        $this->actingAs(User::factory()->operator()->create())
            ->get(route('pengguna.foto', $user))
            ->assertOk();
    }

    public function test_an_account_without_staff_record_stays_closed_to_someone_who_may_not_create_staff(): void
    {
        $user = $this->userWithPhoto();
        $guru = User::factory()->create();
        $guru->assignRole('guru');

        $this->actingAs($guru)->get(route('pengguna.foto', $user))->assertForbidden();
    }

    public function test_someone_allowed_to_view_the_staff_record_sees_the_staff_photo(): void
    {
        $user = $this->userWithPhoto();
        Ptk::factory()->for($user)->create();

        $this->actingAs(User::factory()->operator()->create())
            ->get(route('pengguna.foto', $user))
            ->assertOk();
    }

    public function test_the_photo_of_a_staff_member_stays_closed_to_someone_not_allowed_to_view_staff(): void
    {
        $user = $this->userWithPhoto();
        Ptk::factory()->for($user)->create();
        $guru = User::factory()->create();
        $guru->assignRole('guru');

        $this->actingAs($guru)->get(route('pengguna.foto', $user))->assertForbidden();
    }

    public function test_someone_allowed_to_view_the_student_sees_the_student_photo(): void
    {
        $user = $this->userWithPhoto();
        Siswa::factory()->for($user)->create();

        $this->actingAs(User::factory()->operator()->create())
            ->get(route('pengguna.foto', $user))
            ->assertOk();
    }

    public function test_the_photo_of_a_student_stays_closed_to_someone_not_allowed_to_view_students(): void
    {
        $user = $this->userWithPhoto();
        Siswa::factory()->for($user)->create();
        $guru = User::factory()->create();
        $guru->assignRole('guru');

        $this->actingAs($guru)->get(route('pengguna.foto', $user))->assertForbidden();
    }

    public function test_an_inactive_user_gets_403_even_for_their_own_photo(): void
    {
        $user = $this->userWithPhoto();
        $user->forceFill(['status_aktif' => false])->save();

        $this->actingAs($user)->get(route('pengguna.foto', $user))->assertForbidden();
    }

    public function test_a_user_without_photo_gives_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('pengguna.foto', $user))->assertNotFound();
    }

    public function test_a_stored_path_outside_the_photo_folder_is_never_served(): void
    {
        Storage::disk('local')->put('rahasia/data.png', $this->image);
        $user = User::factory()->create(['foto' => 'rahasia/data.png']);

        $this->actingAs($user)->get(route('pengguna.foto', $user))->assertNotFound();
    }

    public function test_a_missing_file_gives_404(): void
    {
        $user = User::factory()->create(['foto' => 'foto-pengguna/hilang.png']);

        $this->actingAs($user)->get(route('pengguna.foto', $user))->assertNotFound();
    }

    private function userWithPhoto(): User
    {
        return User::factory()->create(['foto' => 'foto-pengguna/budi.png']);
    }
}
