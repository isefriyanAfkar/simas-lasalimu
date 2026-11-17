<?php

namespace Tests\Feature\Filament\Resources\Users;

use App\Domains\Foundation\Enums\Agama;
use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use Mockery\MockInterface;
use ReflectionObject;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserBiodataResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string NIK = '7404012345670001';

    private const string NIK_LAIN = '7404012345670002';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    public function test_super_admin_creates_a_user_with_biodata_and_photo(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Siti Aminah',
                'username' => 'siti',
                'peran' => Peran::Operator->value,
                'password' => 'rahasia-baru-123',
                'nik' => self::NIK,
                'jenis_kelamin' => 'perempuan',
                'tempat_lahir' => 'Kendari',
                'tanggal_lahir' => '1990-05-17',
                'agama' => 'Islam',
                'alamat' => 'Jl. Pendidikan',
                'no_hp' => '0812-3456-7890',
                'foto' => UploadedFile::fake()->image('siti.png'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('username', 'siti')->sole();
        $this->assertSame(self::NIK, $user->nik);
        $this->assertSame(JenisKelamin::Perempuan, $user->jenis_kelamin);
        $this->assertSame('1990-05-17', $user->tanggal_lahir?->toDateString());
        $this->assertSame(Agama::Islam, $user->agama);
        $this->assertSame('6281234567890', $user->no_hp);
        $this->assertStringStartsWith('foto-pengguna/', (string) $user->foto);
        Storage::disk('local')->assertExists((string) $user->foto);
    }

    public function test_service_errors_land_on_the_biodata_fields(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->userWithNik(self::NIK, 'pemilik');

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Siti',
                'username' => 'siti',
                'peran' => Peran::Operator->value,
                'password' => 'rahasia-baru-123',
                'nik' => self::NIK,
                'no_hp' => '123',
            ])
            ->call('create')
            ->assertHasFormErrors(['nik' => ['NIK ini sudah terdaftar pada pengguna lain.'], 'no_hp'])
            ->assertDontSee(self::NIK);
    }

    public function test_edit_form_shows_the_nik_masked_and_never_in_full(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $user = $this->userWithNik(self::NIK);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertSchemaStateSet(['nik' => null], 'form')
            ->assertSee('7404********0001')
            ->assertSee(UserForm::KETERANGAN_NIK)
            ->assertDontSee(self::NIK);
    }

    public function test_an_empty_nik_field_keeps_the_stored_nik(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $user = $this->userWithNik(self::NIK);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['tempat_lahir' => 'Kolaka'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(self::NIK, $user->fresh()->nik);
        $this->assertSame('Kolaka', $user->fresh()->tempat_lahir);
    }

    public function test_hapus_nik_toggle_clears_the_nik_and_logs_it_without_value(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $user = $this->userWithNik(self::NIK);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['hapus_nik' => true])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertFormFieldHidden('hapus_nik');

        $raw = DB::table('users')->where('id', $user->id)->first(['nik', 'nik_hash']);
        $this->assertNull($raw->nik);
        $this->assertNull($raw->nik_hash);

        $activity = Activity::query()->where('event', 'diubah')->where('subject_id', $user->id)->sole();
        $this->assertSame(['nik' => 'dihapus'], $activity->attribute_changes['attributes']);
    }

    public function test_hapus_nik_toggle_is_offered_only_when_a_nik_is_stored(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(EditUser::class, ['record' => User::factory()->create()->getRouteKey()])
            ->assertFormFieldHidden('hapus_nik');

        Livewire::test(EditUser::class, ['record' => $this->userWithNik(self::NIK)->getRouteKey()])
            ->assertFormFieldVisible('hapus_nik');
    }

    public function test_a_new_nik_replaces_the_stored_one_and_the_field_is_emptied_again(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $user = $this->userWithNik(self::NIK);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['nik' => self::NIK_LAIN])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSchemaStateSet(['nik' => null], 'form')
            ->assertSee('7404********0002');

        $this->assertSame(self::NIK_LAIN, $user->fresh()->nik);
    }

    public function test_tampilkan_nik_is_hidden_without_the_view_nik_permission(): void
    {
        $admin = $this->operatorWithoutViewNik(['ViewAny:User', 'View:User', 'Update:User']);
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $this->userWithNik(self::NIK, withRole: false)->getRouteKey()])
            ->assertOk()
            ->assertActionHidden('tampilkanNik');
    }

    public function test_tampilkan_nik_is_hidden_when_no_nik_is_stored(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(EditUser::class, ['record' => User::factory()->create()->getRouteKey()])
            ->assertActionHidden('tampilkanNik');
    }

    public function test_tampilkan_nik_shows_the_nik_and_logs_who_saw_whose_nik(): void
    {
        $operator = User::factory()->operator()->create();
        $operator->givePermissionTo(['ViewAny:User', 'View:User', 'Update:User']);
        $user = $this->userWithNik(self::NIK, withRole: false);
        $this->actingAs($operator);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertDontSee(self::NIK)
            ->callAction('tampilkanNik')
            ->assertSet('nikTerbuka', self::NIK)
            ->assertSee(self::NIK);

        $activity = Activity::query()->where('event', 'nik_ditampilkan')->sole();
        $this->assertTrue($activity->causer?->is($operator));
        $this->assertTrue($activity->subject?->is($user));
        $this->assertStringNotContainsString(self::NIK, json_encode($activity->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_tampilkan_nik_modal_warns_that_access_is_logged(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(EditUser::class, ['record' => $this->userWithNik(self::NIK)->getRouteKey()])
            ->mountAction('tampilkanNik')
            ->assertMountedActionModalSee('Akses ini dicatat di log aktivitas.')
            ->assertMountedActionModalDontSee(self::NIK);

        $this->assertFalse(Activity::query()->where('event', 'nik_ditampilkan')->exists());
    }

    public function test_list_shows_a_photo_column_and_never_the_nik(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $user = $this->userWithNik(self::NIK);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$user])
            ->assertTableColumnExists('foto')
            ->assertTableColumnDoesNotExist('nik')
            ->assertDontSee(self::NIK)
            ->assertDontSee('7404********0001');
    }

    public function test_the_table_search_box_never_matches_a_nik(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $user = $this->userWithNik(self::NIK);

        Livewire::test(ListUsers::class)
            ->searchTable(self::NIK)
            ->assertCanNotSeeTableRecords([$user]);
    }

    public function test_cari_nik_shows_the_matching_user_without_the_nik_and_logs_it(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin);
        $user = $this->userWithNik(self::NIK);
        $this->userWithNik(self::NIK_LAIN, 'lain');

        $component = Livewire::test(ListUsers::class)
            ->callAction('cariNik', data: ['nik' => self::NIK])
            ->assertHasNoActionErrors()
            ->assertNotified('NIK ditemukan: Budi Santoso (budi)');

        $this->assertNikNotInUrlState($component->instance());
        $this->assertSame('', $component->get('tableSearch'));

        $activity = Activity::query()->where('event', 'nik_dicari')->sole();
        $this->assertTrue($activity->causer?->is($superAdmin));
        $this->assertTrue($activity->subject?->is($user));
    }

    public function test_cari_nik_without_a_match_says_so(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $component = Livewire::test(ListUsers::class)
            ->callAction('cariNik', data: ['nik' => self::NIK])
            ->assertHasNoActionErrors()
            ->assertNotified('Tidak ada pengguna dengan NIK ini.');

        $this->assertNikNotInUrlState($component->instance());
    }

    public function test_cari_nik_shows_an_invalid_nik_as_a_field_error(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $component = Livewire::test(ListUsers::class)
            ->callAction('cariNik', data: ['nik' => '123'])
            ->assertHasActionErrors(['nik']);

        $this->assertNikNotInUrlState($component->instance(), '123');
        $this->assertFalse(Activity::query()->where('event', 'nik_dicari')->exists());
    }

    public function test_cari_nik_is_hidden_without_the_view_nik_permission(): void
    {
        $this->actingAs($this->operatorWithoutViewNik(['ViewAny:User']));

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertActionHidden('cariNik');
    }

    public function test_cari_nik_is_offered_to_an_operator_with_view_nik(): void
    {
        $operator = User::factory()->operator()->create();
        $operator->givePermissionTo(['ViewAny:User']);
        $this->actingAs($operator);

        Livewire::test(ListUsers::class)
            ->assertActionVisible('cariNik');
    }

    public function test_tampilkan_nik_is_hidden_for_a_user_the_operator_may_not_manage(): void
    {
        $operator = User::factory()->operator()->create();
        $operator->givePermissionTo(['ViewAny:User', 'View:User', 'Update:User']);
        $this->actingAs($operator);

        Livewire::test(EditUser::class, ['record' => $operator->getRouteKey()])
            ->assertActionHidden('tampilkanNik');

        $operator->isiNik(self::NIK);
        $operator->save();

        Livewire::test(EditUser::class, ['record' => $operator->getRouteKey()])
            ->assertActionVisible('tampilkanNik');
    }

    public function test_tampilkan_nik_turns_a_rule_violation_into_a_notification(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $user = $this->userWithNik(self::NIK);
        $this->partialMock(UserService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('tampilkanNik')->andThrow(PelanggaranAturanPengguna::nikKosong());
        });

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->callAction('tampilkanNik')
            ->assertNotified('Pengguna ini belum punya NIK.')
            ->assertSet('nikTerbuka', null);
    }

    public function test_list_photo_column_points_to_the_protected_photo_route(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('foto-pengguna/budi.png', UploadedFile::fake()->image('budi.png')->getContent());
        $this->actingAs(User::factory()->superAdmin()->create());
        $user = User::factory()->create(['foto' => 'foto-pengguna/budi.png']);

        Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('foto', $user->urlFoto(), $user)
            ->assertDontSee('foto-pengguna/budi.png');

        $this->assertStringStartsWith(route('pengguna.foto', $user), (string) $user->urlFoto());
    }

    /**
     * Every property Livewire binds to the query string (#[Url]) must stay
     * free of the NIK.
     */
    private function assertNikNotInUrlState(object $component, string $nik = self::NIK): void
    {
        $urlState = [];

        foreach ((new ReflectionObject($component))->getProperties() as $property) {
            if ($property->getAttributes(Url::class) !== []) {
                $urlState[$property->getName()] = $property->getValue($component);
            }
        }

        $this->assertArrayHasKey('tableSearch', $urlState);
        $this->assertStringNotContainsString($nik, json_encode($urlState, JSON_THROW_ON_ERROR));
    }

    public function test_searching_by_name_still_works(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $siti = User::factory()->create(['name' => 'Siti Aminah']);
        $budi = User::factory()->create(['name' => 'Budi Santoso']);

        Livewire::test(ListUsers::class)
            ->searchTable('Aminah')
            ->assertCanSeeTableRecords([$siti])
            ->assertCanNotSeeTableRecords([$budi]);
    }

    /**
     * An operator, or with $withRole false a user without a role, which an
     * operator may manage.
     */
    private function userWithNik(string $nik, string $username = 'budi', bool $withRole = true): User
    {
        $factory = User::factory();
        $user = ($withRole ? $factory->operator() : $factory)->create(['name' => ucfirst($username).' Santoso', 'username' => $username]);
        $user->isiNik($nik);
        $user->save();

        return $user;
    }

    /**
     * An operator (so it may enter the panel) whose role lost ViewNik:User.
     *
     * @param  list<string>  $permissions
     */
    private function operatorWithoutViewNik(array $permissions): User
    {
        Role::findByName(Peran::Operator->value, 'web')->revokePermissionTo('ViewNik:User');

        $operator = User::factory()->operator()->create();
        $operator->givePermissionTo($permissions);

        $this->assertFalse($operator->can('ViewNik:User'));

        return $operator;
    }
}
