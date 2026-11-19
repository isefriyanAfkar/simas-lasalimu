<?php

namespace Tests\Feature\Domains\People\Services;

use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\HubunganWali;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\SiswaWali;
use App\Domains\People\Models\StatusKepegawaian;
use App\Domains\People\Models\WaliMurid;
use App\Domains\People\Services\PelanggaranAturanKesiswaan;
use App\Domains\People\Services\PtkService;
use App\Domains\People\Services\SiswaWaliService;
use App\Domains\People\Services\WaliMuridService;
use Closure;
use Database\Seeders\PeranDanIzinSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Parent records on their own: searching (by name or a phone number in any
 * format), the duplicate warning, editing, the died flag, deleting a parent
 * without children, and the rules that keep a parent account a parent
 * account.
 */
class WaliMuridServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string NIK = '7404012345670004';

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        $this->operator = User::factory()->operator()->create(['name' => 'Operator', 'username' => 'operator']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function phoneFormats(): array
    {
        return [
            '08' => ['081234567890'],
            '+62' => ['+6281234567890'],
            '62' => ['6281234567890'],
            'bertanda hubung' => ['0812-3456-7890'],
            'potongan awal 08' => ['0812345'],
            'potongan tengah' => ['4567'],
        ];
    }

    #[DataProvider('phoneFormats')]
    public function test_search_finds_a_phone_number_in_any_format(string $kataKunci): void
    {
        $ahmad = $this->wali('Ahmad', ['no_hp' => '6281234567890']);
        $this->wali('Siti', ['no_hp' => '6285299990000']);

        $this->assertSame([$ahmad->id], $this->service()->cari($kataKunci)->modelKeys());
    }

    public function test_search_finds_a_name_and_matches_percent_and_underscore_literally(): void
    {
        $ahmad = $this->wali('Ahmad Yani');
        $this->wali('Siti 100%');

        $this->assertSame([$ahmad->id], $this->service()->cari('yani')->modelKeys());
        $this->assertSame(['Siti 100%'], $this->service()->cari('%')->map(fn (WaliMurid $wali): string => $wali->user->name)->all());
        $this->assertSame([], $this->service()->cari('_')->all());
    }

    public function test_duplicate_candidates_match_the_phone_number_in_another_format_or_the_nik(): void
    {
        $ahmad = $this->wali('Ahmad', ['no_hp' => '6281234567890']);
        $siti = $this->wali('Siti', ['nik' => self::NIK]);
        $this->wali('Lain', ['no_hp' => '6285299990000']);

        $this->assertSame([$ahmad->id], $this->service()->calonGanda($this->operator, '0812 3456 7890', null)->modelKeys());
        $this->assertSame([$ahmad->id], $this->service()->calonGanda($this->operator, '+6281234567890', null)->modelKeys());
        $this->assertSame([$siti->id], $this->service()->calonGanda($this->operator, null, self::NIK)->modelKeys());
        $this->assertSame([], $this->service()->calonGanda($this->operator, '0811', null)->all());
        $this->assertSame([], $this->service()->calonGanda($this->operator, null, null)->all());
    }

    public function test_editing_changes_the_biodata_and_the_job_but_never_the_username(): void
    {
        $ahmad = $this->wali('Ahmad');
        $username = $ahmad->user->username;

        $this->service()->ubah($this->operator, $ahmad, $this->editData($ahmad, ['name' => 'Ahmad Yani', 'no_hp' => '0811000222', 'pekerjaan' => 'Nelayan', 'username' => 'lain']));

        $ahmad = $ahmad->fresh();
        $this->assertSame('Ahmad Yani', $ahmad->user->name);
        $this->assertSame('62811000222', $ahmad->user->no_hp);
        $this->assertSame('Nelayan', $ahmad->pekerjaan);
        $this->assertSame($username, $ahmad->user->username);
        $this->assertFalse($ahmad->user->status_aktif);
    }

    public function test_the_gender_cannot_contradict_a_relation_to_any_student(): void
    {
        $ahmad = $this->wali('Ahmad', ['jenis_kelamin' => 'laki-laki']);
        $this->hubungkan($ahmad, 'Ani', HubunganWali::Wali);
        $this->hubungkan($ahmad, 'Budi', HubunganWali::Ayah);

        try {
            $this->service()->ubah($this->operator, $ahmad, $this->editData($ahmad, ['jenis_kelamin' => 'perempuan']));
            $this->fail('A father became female.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Ahmad adalah ayah Budi, jadi harus laki-laki.'], $exception->errors()['jenis_kelamin']);
        }

        $this->assertSame(JenisKelamin::LakiLaki, $ahmad->user->fresh()->jenis_kelamin);
    }

    public function test_an_empty_gender_is_filled_in_from_the_relation(): void
    {
        $siti = $this->wali('Siti', ['jenis_kelamin' => 'perempuan']);
        $this->hubungkan($siti, 'Budi', HubunganWali::Ibu);

        $this->service()->ubah($this->operator, $siti, $this->editData($siti, ['jenis_kelamin' => null]));

        $this->assertSame(JenisKelamin::Perempuan, $siti->user->fresh()->jenis_kelamin);
    }

    public function test_a_guardian_relation_leaves_the_gender_free(): void
    {
        $paman = $this->wali('Paman', ['jenis_kelamin' => 'laki-laki']);
        $this->hubungkan($paman, 'Budi', HubunganWali::Wali);

        $this->service()->ubah($this->operator, $paman, $this->editData($paman, ['jenis_kelamin' => 'perempuan']));

        $this->assertSame(JenisKelamin::Perempuan, $paman->user->fresh()->jenis_kelamin);
    }

    public function test_the_main_contact_cannot_be_marked_as_died_before_another_is_chosen(): void
    {
        $ahmad = $this->wali('Ahmad');
        $this->hubungkan($ahmad, 'Budi', HubunganWali::Wali, utama: true);
        $this->hubungkan($ahmad, 'Ani', HubunganWali::Wali, utama: true);
        $this->hubungkan($ahmad, 'Citra', HubunganWali::Wali);

        $this->assertRejected('Ahmad masih wali utama Ani, Budi. Pilih wali utama lain dulu.', fn () => $this->service()->ubahStatusMeninggal($this->operator, $ahmad, true));

        $this->assertFalse($ahmad->fresh()->meninggal);
    }

    public function test_marking_as_died_and_back_is_logged(): void
    {
        $ahmad = $this->wali('Ahmad');
        $this->hubungkan($ahmad, 'Budi', HubunganWali::Wali);

        $this->service()->ubahStatusMeninggal($this->operator, $ahmad, true);
        $this->assertTrue($ahmad->fresh()->meninggal);
        $this->service()->ubahStatusMeninggal($this->operator, $ahmad->fresh(), false);
        $this->assertFalse($ahmad->fresh()->meninggal);

        $log = Activity::query()->where('event', 'wali_ditandai_meninggal')->sole();
        $this->assertTrue($log->subject->is($ahmad));
        $this->assertSame(['meninggal' => true], $log->attribute_changes['attributes']);
        $this->assertSame(1, Activity::query()->where('event', 'tanda_meninggal_wali_dibatalkan')->count());
    }

    public function test_a_parent_without_children_is_deleted_with_the_account_and_photo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('foto-pengguna/ahmad.png', UploadedFile::fake()->image('ahmad.png')->getContent());
        $ahmad = $this->wali('Ahmad', ['foto' => 'foto-pengguna/ahmad.png']);
        $userId = $ahmad->user_id;

        $this->service()->hapus($this->operator, $ahmad);

        $this->assertNull(WaliMurid::query()->find($ahmad->id));
        $this->assertNull(User::query()->find($userId));
        Storage::disk('local')->assertMissing('foto-pengguna/ahmad.png');

        $log = Activity::query()->where('event', 'wali_dihapus')->sole();
        $this->assertSame('Ahmad', $log->getProperty('label'));
        $this->assertTrue($log->causer->is($this->operator));
        $this->assertSame(1, Activity::query()->where('log_name', 'pengguna')->where('event', 'dihapus')->count());
    }

    public function test_a_parent_still_linked_to_students_cannot_be_deleted(): void
    {
        $ahmad = $this->wali('Ahmad');
        $this->hubungkan($ahmad, 'Budi', HubunganWali::Wali);
        $this->hubungkan($ahmad, 'Ani', HubunganWali::Wali);

        $this->assertRejected('Ahmad masih terhubung ke 2 siswa. Lepas dulu dari semua siswanya.', fn () => $this->service()->hapus($this->operator, $ahmad));

        $this->assertNotNull($ahmad->fresh());
    }

    public function test_kelola_pengguna_keeps_a_parent_account_a_parent_account(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = $this->wali('Ahmad')->user->fresh();
        $users = app(UserService::class);

        $this->assertValidationError('username', fn () => $users->update($superAdmin, $user, ['name' => 'Ahmad', 'username' => 'ahmad', 'email' => null, 'peran' => null]));
        $this->assertValidationError('peran', fn () => $users->update($superAdmin, $user, ['name' => 'Ahmad', 'username' => $user->username, 'email' => null, 'peran' => 'operator']));
        $this->assertValidationError('peran', fn () => $users->update($superAdmin, User::factory()->create(['username' => 'lain']), ['name' => 'Lain', 'username' => 'lain', 'email' => null, 'peran' => 'wali']));

        try {
            $users->activate($superAdmin, $user);
            $this->fail('A parent account was activated.');
        } catch (PelanggaranAturanPengguna $exception) {
            $this->assertSame(PelanggaranAturanPengguna::akunWaliBelumBisaDiaktifkan()->getMessage(), $exception->getMessage());
        }

        $user = $user->fresh();
        $this->assertFalse($user->status_aktif);
        $this->assertSame(['wali'], $user->getRoleNames()->all());
    }

    public function test_a_parent_account_cannot_be_linked_as_staff(): void
    {
        $user = $this->wali('Ahmad')->user;

        try {
            app(PtkService::class)->create(User::factory()->superAdmin()->create(), [
                'akun' => 'ada',
                'user_id' => $user->id,
                'name' => 'Ahmad',
                'username' => $user->username,
                'jenis_kelamin' => 'laki-laki',
                'jenis_ptk' => 'guru',
                'status_kepegawaian_id' => StatusKepegawaian::query()->where('nama', 'PNS')->value('id'),
                'sertifikasi' => false,
            ]);
            $this->fail('A parent account was linked as staff.');
        } catch (ValidationException $exception) {
            $this->assertSame([PtkService::PESAN_AKUN_WALI], $exception->errors()['user_id']);
        }

        $this->assertSame(0, Ptk::query()->count());
    }

    public function test_a_nik_match_warns_and_is_logged_only_for_whoever_may_look_a_nik_up(): void
    {
        $siti = $this->wali('Siti', ['nik' => self::NIK, 'no_hp' => '081299990000']);
        Activity::query()->delete();

        $this->assertSame([$siti->id], $this->service()->calonGanda($this->operator, null, self::NIK)->modelKeys());
        $log = Activity::query()->where('event', 'nik_dicari')->sole();
        $this->assertTrue($log->subject->is($siti->user));
        $this->assertStringNotContainsString(self::NIK, json_encode($log->toArray()));

        Role::findByName('operator', 'web')->revokePermissionTo('ViewNik:User');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $tanpaIzin = User::factory()->operator()->create();

        $this->assertSame([], $this->service()->calonGanda($tanpaIzin, null, self::NIK)->all());
        $this->assertSame([$siti->id], $this->service()->calonGanda($tanpaIzin, '+6281299990000', self::NIK)->modelKeys());
        $this->assertSame(1, Activity::query()->where('event', 'nik_dicari')->count());
    }

    public function test_kelola_pengguna_follows_the_gender_rule_of_a_parent(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $ahmad = $this->wali('Ahmad', ['jenis_kelamin' => 'laki-laki']);
        $this->hubungkan($ahmad, 'Budi', HubunganWali::Ayah);
        $user = $ahmad->user->fresh();
        $data = ['name' => 'Ahmad', 'username' => $user->username, 'email' => null, 'peran' => null];

        try {
            app(UserService::class)->update($superAdmin, $user, [...$data, 'jenis_kelamin' => 'perempuan']);
            $this->fail('A father became female in Kelola Pengguna.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Ahmad adalah ayah Budi, jadi harus laki-laki.'], $exception->errors()['jenis_kelamin']);
        }

        app(UserService::class)->update($superAdmin, $user->fresh(), [...$data, 'jenis_kelamin' => null]);
        $this->assertSame(JenisKelamin::LakiLaki, $user->fresh()->jenis_kelamin);
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function accountsThatAreNotParents(): array
    {
        return [
            'tanpa role' => [fn (): User => User::factory()->create()],
            'operator' => [fn (): User => User::factory()->operator()->create()],
            'wali yang juga guru' => [function (): User {
                $user = User::factory()->create();
                $user->assignRole('wali');
                Ptk::factory()->for($user)->create();

                return $user;
            }],
        ];
    }

    /**
     * @param  Closure(): User  $akun
     */
    #[DataProvider('accountsThatAreNotParents')]
    public function test_deleting_an_account_is_refused_unless_it_is_a_plain_parent_account(Closure $akun): void
    {
        $user = $akun();

        try {
            app(UserService::class)->hapusAkunWali(User::factory()->superAdmin()->create(), $user);
            $this->fail('A non-parent account was deleted.');
        } catch (LogicException) {
            $this->assertNotNull($user->fresh());
        }
    }

    private function service(): WaliMuridService
    {
        return app(WaliMuridService::class);
    }

    /**
     * A parent created through SiswaWaliService's new-parent path, so the
     * account follows every rule, then let go so the record stands alone.
     *
     * @param  array<string, mixed>  $override
     */
    private function wali(string $nama, array $override = []): WaliMurid
    {
        $siswa = Siswa::factory()->create();
        $siswaWali = app(SiswaWaliService::class)->tambahkan($this->operator, $siswa, [
            'wali' => 'baru',
            'hubungan' => 'wali',
            'name' => $nama,
            'jenis_kelamin' => null,
            ...$override,
        ]);
        $wali = $siswaWali->waliMurid;
        SiswaWali::query()->whereKey($siswaWali->id)->delete();

        return $wali->fresh();
    }

    private function hubungkan(WaliMurid $wali, string $namaSiswa, HubunganWali $hubungan, bool $utama = false): void
    {
        SiswaWali::factory()
            ->for(Siswa::factory()->for(User::factory()->state(['name' => $namaSiswa])))
            ->for($wali)
            ->create(['hubungan' => $hubungan, 'utama' => $utama]);
    }

    /**
     * The edit payload of $wali as stored, with $override applied.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function editData(WaliMurid $wali, array $override = []): array
    {
        $wali = $wali->fresh();
        $user = $wali->user;

        return [
            'name' => $user->name,
            'email' => $user->email,
            'nik' => null,
            'hapus_nik' => false,
            'jenis_kelamin' => $user->jenis_kelamin?->value,
            'no_hp' => $user->no_hp,
            'alamat' => $user->alamat,
            'pekerjaan' => $wali->pekerjaan,
            ...$override,
        ];
    }

    private function assertRejected(string $message, Closure $operation): void
    {
        try {
            $operation();
        } catch (PelanggaranAturanKesiswaan $exception) {
            $this->assertSame($message, $exception->getMessage());

            return;
        }

        $this->fail('Expected the operation to be rejected.');
    }

    private function assertValidationError(string $field, Closure $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors(), 'Errors: '.json_encode($exception->errors()));

            return;
        }

        $this->fail("Expected a validation error on [{$field}].");
    }
}
