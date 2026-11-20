<?php

namespace Tests\Feature\Domains\People\Services;

use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\StatusSiswa;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Services\SiswaService;
use Closure;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Creating and changing students: the account whose username is the NIS,
 * biodata through UserService's rules, and the student fields. Status
 * changes are in SiswaStatusTest.
 */
class SiswaServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string NIK = '7404012345670002';

    private const string NISN = '0081234567';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
        $this->travelTo('2026-11-18 10:00:00');
    }

    public function test_creates_a_student_with_an_account_whose_username_is_the_nis(): void
    {
        $operator = User::factory()->operator()->create();

        $siswa = $this->service()->create($operator, $this->data([
            'name' => '  Budi   Santoso ',
            'nis' => ' 20261001 ',
            'nisn' => self::NISN,
            'nik' => self::NIK,
            'tanggal_masuk' => '2026-07-13',
            'asal_sekolah' => '  SMP Negeri   1 ',
        ]))->fresh();

        $user = $siswa->user;
        $this->assertSame('Budi   Santoso', $user->name);
        $this->assertSame('20261001', $user->username);
        $this->assertTrue($user->status_aktif);
        $this->assertSame(self::NIK, $user->nik);
        $this->assertSame(JenisKelamin::LakiLaki, $user->jenis_kelamin);
        $this->assertSame(['siswa'], $user->getRoleNames()->all());
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));

        $this->assertSame('20261001', $siswa->nis);
        $this->assertSame(self::NISN, $siswa->nisn);
        $this->assertSame('2026-07-13', $siswa->tanggal_masuk->toDateString());
        $this->assertSame('SMP Negeri 1', $siswa->asal_sekolah);
        $this->assertSame(StatusSiswa::Aktif, $siswa->status);
        $this->assertNull($siswa->tanggal_keluar);
    }

    public function test_a_username_typed_in_the_data_is_ignored(): void
    {
        $siswa = $this->service()->create(User::factory()->operator()->create(), $this->data(['username' => 'budi']));

        $this->assertSame('20261001', $siswa->user->fresh()->username);
    }

    /**
     * Guru, tendik and siswa hold no panel access, so canGrantRole() never
     * refuses them today. The stub proves the check is still consulted on
     * this path and that a refusal leaves nothing behind.
     */
    public function test_the_student_role_goes_through_the_grant_check(): void
    {
        $operator = User::factory()->operator()->create();
        $this->partialMock(UserService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('canGrantRole')
            ->andReturnUsing(fn (User $pelaku, Peran $peran): bool => $peran !== Peran::Siswa));

        try {
            $this->service()->create($operator, $this->data());
            $this->fail('A refused role was granted.');
        } catch (PelanggaranAturanPengguna $exception) {
            $this->assertSame(PelanggaranAturanPengguna::tidakBolehMemberiPeran('siswa')->getMessage(), $exception->getMessage());
        }

        $this->assertSame(0, Siswa::query()->count());
        $this->assertDatabaseMissing('users', ['username' => '20261001']);
    }

    public function test_a_new_account_gets_a_random_password_that_is_never_returned(): void
    {
        $operator = User::factory()->operator()->create();

        $pertama = $this->service()->create($operator, $this->data(['nis' => '20261001']))->user->fresh();
        $kedua = $this->service()->create($operator, $this->data(['nis' => '20261002']))->user->fresh();

        $this->assertNotEmpty($pertama->getAuthPassword());
        $this->assertNotSame($pertama->getAuthPassword(), $kedua->getAuthPassword());
        $this->assertFalse(password_verify('', $pertama->getAuthPassword()));
        $this->assertFalse(password_verify('password', $pertama->getAuthPassword()));
        $this->assertFalse(password_verify('20261001', $pertama->getAuthPassword()));
    }

    public function test_changing_the_nis_changes_the_username_and_is_logged(): void
    {
        $operator = User::factory()->operator()->create();
        $siswa = $this->service()->create($operator, $this->data());

        $this->service()->update($operator, $siswa, $this->editData($siswa, ['nis' => '20269999']));

        $siswa = $siswa->fresh();
        $this->assertSame('20269999', $siswa->nis);
        $this->assertSame('20269999', $siswa->user->username);

        $akunLog = Activity::query()->where('log_name', 'pengguna')->where('event', 'diubah')->sole();
        $this->assertSame(['username' => '20269999'], $akunLog->attribute_changes['attributes']);
        $this->assertSame(['username' => '20261001'], $akunLog->attribute_changes['old']);

        $siswaLog = Activity::query()->where('log_name', 'kesiswaan')->where('event', 'updated')->sole();
        $this->assertSame(['nis' => '20269999'], $siswaLog->attribute_changes['attributes']);
    }

    public function test_kelola_pengguna_cannot_change_the_username_of_a_student_account(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $siswa = $this->service()->create($superAdmin, $this->data());
        $user = $siswa->user->fresh();

        $this->assertValidationError('username', fn () => app(UserService::class)->update($superAdmin, $user, [
            'name' => $user->name,
            'username' => 'budi',
            'email' => null,
            'peran' => null,
        ]));

        $this->assertSame('20261001', $user->fresh()->username);

        app(UserService::class)->update($superAdmin, $user, ['name' => 'Budi S', 'username' => '20261001', 'email' => null, 'peran' => null]);
        $this->assertSame('Budi S', $user->fresh()->name);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function panelRoles(): array
    {
        return [
            'operator' => ['operator'],
            'super_admin' => ['super_admin'],
        ];
    }

    #[DataProvider('panelRoles')]
    public function test_kelola_pengguna_cannot_give_a_student_account_another_role(string $peran): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = $this->service()->create($superAdmin, $this->data())->user->fresh();

        try {
            app(UserService::class)->update($superAdmin, $user, ['name' => $user->name, 'username' => $user->username, 'email' => null, 'peran' => $peran]);
            $this->fail('A student account got another role.');
        } catch (ValidationException $exception) {
            $this->assertSame([UserService::PESAN_PERAN_SISWA], $exception->errors()['peran']);
        }

        $this->assertSame(['siswa'], $user->fresh()->getRoleNames()->all());
    }

    public function test_kelola_pengguna_cannot_give_the_student_role_to_an_account_without_a_student_record(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->create(['username' => 'budi']);

        $this->assertValidationError('peran', fn () => app(UserService::class)->update($superAdmin, $user, ['name' => $user->name, 'username' => 'budi', 'email' => null, 'peran' => 'siswa']));
        $this->assertValidationError('peran', fn () => app(UserService::class)->create($superAdmin, ['name' => 'Ani', 'username' => 'ani', 'email' => null, 'password' => 'Rahasia#2026!', 'peran' => 'siswa']));

        $this->assertSame([], $user->fresh()->getRoleNames()->all());
        $this->assertDatabaseMissing('users', ['username' => 'ani']);
    }

    public function test_kelola_pengguna_still_changes_the_username_of_other_accounts(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->create(['username' => 'lama']);

        app(UserService::class)->update($superAdmin, $user, ['name' => $user->name, 'username' => 'baru', 'email' => null, 'peran' => null]);

        $this->assertSame('baru', $user->fresh()->username);
    }

    public function test_empty_optional_fields_are_stored_as_null_and_may_repeat(): void
    {
        $operator = User::factory()->operator()->create();
        $kosong = ['nisn' => ' ', 'tanggal_masuk' => '', 'asal_sekolah' => '', 'nik' => null];

        $pertama = $this->service()->create($operator, $this->data(['nis' => '20261001', ...$kosong]))->fresh();
        $kedua = $this->service()->create($operator, $this->data(['nis' => '20261002', ...$kosong]))->fresh();

        foreach ([$pertama, $kedua] as $siswa) {
            $this->assertNull($siswa->nisn);
            $this->assertNull($siswa->tanggal_masuk);
            $this->assertNull($siswa->asal_sekolah);
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidData(): array
    {
        return [
            'nama kosong' => [['name' => ' '], 'name'],
            'jenis kelamin kosong' => [['jenis_kelamin' => null], 'jenis_kelamin'],
            'jenis kelamin tidak dikenal' => [['jenis_kelamin' => 'x'], 'jenis_kelamin'],
            'NIK 15 digit' => [['nik' => '740401234567000'], 'nik'],
            'NIS kosong' => [['nis' => ''], 'nis'],
            'NIS 3 angka' => [['nis' => '123'], 'nis'],
            'NIS 21 angka' => [['nis' => str_repeat('1', 21)], 'nis'],
            'NIS berhuruf' => [['nis' => '2026A001'], 'nis'],
            'NIS berspasi' => [['nis' => '2026 1001'], 'nis'],
            'NIS bertanda hubung' => [['nis' => '2026-1001'], 'nis'],
            'NISN 9 angka' => [['nisn' => '008123456'], 'nisn'],
            'NISN 11 angka' => [['nisn' => '00812345678'], 'nisn'],
            'NISN berhuruf' => [['nisn' => '008123456A'], 'nisn'],
            'tanggal masuk besok' => [['tanggal_masuk' => '2026-11-19'], 'tanggal_masuk'],
            'tanggal masuk bukan Y-m-d' => [['tanggal_masuk' => '13/07/2026'], 'tanggal_masuk'],
            'asal sekolah lebih dari 150' => [['asal_sekolah' => str_repeat('a', 151)], 'asal_sekolah'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidData')]
    public function test_create_rejects_invalid_data(array $override, string $field): void
    {
        $this->assertValidationError($field, fn () => $this->service()->create(User::factory()->operator()->create(), $this->data($override)));

        $this->assertSame(0, Siswa::query()->count());
        $this->assertSame(1, User::query()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nisAtTheLimits(): array
    {
        return [
            '4 angka' => ['1234'],
            '20 angka' => [str_repeat('9', 20)],
        ];
    }

    #[DataProvider('nisAtTheLimits')]
    public function test_a_nis_of_4_or_20_digits_is_accepted(string $nis): void
    {
        $siswa = $this->service()->create(User::factory()->operator()->create(), $this->data(['nis' => $nis]));

        $this->assertSame($nis, $siswa->fresh()->user->username);
    }

    public function test_the_entry_date_may_be_today(): void
    {
        $siswa = $this->service()->create(User::factory()->operator()->create(), $this->data(['tanggal_masuk' => '2026-11-18']));

        $this->assertSame('2026-11-18', $siswa->fresh()->tanggal_masuk->toDateString());
    }

    public function test_errors_of_the_account_and_of_the_student_fields_are_reported_together(): void
    {
        try {
            $this->service()->create(User::factory()->operator()->create(), $this->data(['jenis_kelamin' => null, 'nisn' => '123']));
            $this->fail('Invalid data was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('jenis_kelamin', $exception->errors());
            $this->assertArrayHasKey('nisn', $exception->errors());
        }
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function uniqueFields(): array
    {
        return [
            'NIS' => ['nis', '20261001', 'NIS ini sudah dipakai oleh Budi.'],
            'NISN' => ['nisn', self::NISN, 'NISN ini sudah dipakai oleh Budi.'],
        ];
    }

    #[DataProvider('uniqueFields')]
    public function test_a_taken_value_is_rejected_with_the_holders_name(string $field, string $value, string $message): void
    {
        $operator = User::factory()->operator()->create();
        $this->service()->create($operator, $this->data(['name' => 'Budi', 'nis' => '20261001', 'nisn' => self::NISN]));

        try {
            $this->service()->create($operator, $this->data(['name' => 'Ani', 'nis' => '20261002', 'nisn' => null, $field => $value]));
            $this->fail('A taken value was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame([$message], $exception->errors()[$field]);
        }

        $this->assertSame(1, Siswa::query()->count());
    }

    public function test_a_nis_taken_as_the_username_of_another_account_is_rejected_on_the_nis_field(): void
    {
        User::factory()->create(['name' => 'Pak Guru', 'username' => '20261001']);

        try {
            $this->service()->create(User::factory()->operator()->create(), $this->data(['nis' => '20261001']));
            $this->fail('A taken username was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['NIS ini sudah dipakai sebagai nama pengguna oleh Pak Guru.'], $exception->errors()['nis']);
            $this->assertArrayNotHasKey('username', $exception->errors());
        }

        $this->assertSame(0, Siswa::query()->count());
    }

    #[DataProvider('uniqueFields')]
    public function test_update_may_keep_its_own_unique_values_but_not_take_another(string $field, string $value, string $message): void
    {
        $operator = User::factory()->operator()->create();
        $budi = $this->service()->create($operator, $this->data(['name' => 'Budi', 'nis' => '20261001', 'nisn' => self::NISN]));
        $ani = $this->service()->create($operator, $this->data(['name' => 'Ani', 'nis' => '20261002', 'nisn' => null]));

        $this->service()->update($operator, $budi, $this->editData($budi, ['asal_sekolah' => 'SMP 2']));
        $this->assertSame('SMP 2', $budi->fresh()->asal_sekolah);

        $this->assertValidationError($field, fn () => $this->service()->update($operator, $ani, $this->editData($ani, [$field => $value])));
        $this->assertSame('20261002', $ani->fresh()->user->username);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function raceFields(): array
    {
        return [
            'NIS' => ['nis'],
            'NISN' => ['nisn'],
        ];
    }

    #[DataProvider('raceFields')]
    public function test_a_concurrent_create_with_the_same_value_ends_as_a_field_error(string $field): void
    {
        $lain = User::factory()->create();

        Siswa::creating(function (Siswa $siswa) use ($field, $lain): void {
            DB::table('siswa')->insert([
                'user_id' => $lain->id,
                'nis' => $field === 'nis' ? $siswa->nis : '99999999',
                'nisn' => $field === 'nisn' ? $siswa->nisn : null,
                'status' => 'aktif',
            ]);
        });

        $this->assertValidationError($field, fn () => $this->service()->create(User::factory()->operator()->create(), $this->data(['nisn' => self::NISN])));

        $this->assertSame(0, Siswa::query()->count());
        $this->assertDatabaseMissing('users', ['username' => '20261001']);
    }

    public function test_a_concurrent_account_with_the_nis_as_username_ends_as_an_error_on_the_nis(): void
    {
        User::creating(function (User $user): void {
            if ($user->username === '20261001') {
                DB::table('users')->insert(['name' => 'Lain', 'username' => '20261001', 'password' => 'x', 'status_aktif' => true]);
            }
        });

        try {
            $this->service()->create(User::factory()->operator()->create(), $this->data());
            $this->fail('A taken username was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('nis', $exception->errors());
            $this->assertArrayNotHasKey('username', $exception->errors());
        }

        $this->assertSame(0, Siswa::query()->count());
    }

    public function test_an_operator_may_create_and_change_students(): void
    {
        $operator = User::factory()->operator()->create();
        $siswa = $this->service()->create($operator, $this->data());

        $this->service()->update($operator, $siswa, $this->editData($siswa, ['name' => 'Budi Baru', 'jenis_kelamin' => 'perempuan']));

        $this->assertSame('Budi Baru', $siswa->user->fresh()->name);
        $this->assertSame(JenisKelamin::Perempuan, $siswa->user->fresh()->jenis_kelamin);
    }

    public function test_an_empty_nik_on_update_keeps_the_stored_nik(): void
    {
        $operator = User::factory()->operator()->create();
        $siswa = $this->service()->create($operator, $this->data(['nik' => self::NIK]));

        $this->service()->update($operator, $siswa, $this->editData($siswa, ['nik' => null]));

        $this->assertSame(self::NIK, $siswa->user->fresh()->nik);
    }

    public function test_create_and_update_are_logged_without_the_nik(): void
    {
        $operator = User::factory()->operator()->create();
        $siswa = $this->service()->create($operator, $this->data(['nik' => self::NIK, 'nisn' => self::NISN]));
        $this->service()->update($operator, $siswa, $this->editData($siswa, ['asal_sekolah' => 'SMP 3']));

        $created = Activity::query()->where('event', 'created')->where('subject_type', $siswa->getMorphClass())->sole();
        $this->assertSame('kesiswaan', $created->log_name);
        $this->assertSame('Data siswa dibuat', $created->description);
        $this->assertTrue($created->causer->is($operator));
        $this->assertSame(self::NISN, $created->attribute_changes['attributes']['nisn']);
        $this->assertArrayNotHasKey('status', $created->attribute_changes['attributes']);

        $updated = Activity::query()->where('event', 'updated')->where('subject_type', $siswa->getMorphClass())->sole();
        $this->assertSame(['asal_sekolah' => 'SMP 3'], $updated->attribute_changes['attributes']);

        $dibuat = Activity::query()->where('log_name', 'pengguna')->where('event', 'dibuat')->sole();
        $this->assertNull($dibuat->attribute_changes['attributes']['peran']);
        $this->assertSame('siswa', $dibuat->attribute_changes['attributes']['peran_siswa']);

        foreach (Activity::query()->get() as $activity) {
            $this->assertStringNotContainsString(self::NIK, json_encode($activity->toArray()));
        }
    }

    /**
     * An import saves many students in one transaction: no save locks a
     * whole table; each takes only the siswa and akun identity rows
     * (KunciIdentitas), one primary key lookup each, whatever the number of
     * students.
     */
    public function test_many_saves_in_one_transaction_lock_only_the_identity_rows(): void
    {
        $operator = User::factory()->operator()->create();
        $kunciSemua = [];
        $kunciIdentitas = [];
        $merekam = true;
        DB::listen(function (QueryExecuted $query) use (&$kunciSemua, &$kunciIdentitas, &$merekam): void {
            if (! $merekam) {
                return;
            }

            if (preg_match('/^select `id` from `(users|siswa)` for update$/', $query->sql, $cocok) === 1) {
                $kunciSemua[] = $cocok[1];
            } elseif (str_contains($query->sql, '`kunci_identitas`') && str_ends_with($query->sql, 'for update')) {
                $kunciIdentitas[] = (string) $query->bindings[0];
            }
        });

        DB::transaction(function () use ($operator): void {
            $this->service()->create($operator, $this->data(['nis' => '20261001']));
            $this->service()->create($operator, $this->data(['nis' => '20261002']));
            $this->service()->create($operator, $this->data(['nis' => '20261003']));
        });
        $merekam = false;

        $this->assertSame([], $kunciSemua);
        $this->assertSame(['siswa', 'akun'], array_values(array_unique($kunciIdentitas)));
        $this->assertSame(3, Siswa::query()->count());
    }

    private function service(): SiswaService
    {
        return app(SiswaService::class);
    }

    /**
     * A valid create payload, as the form sends it.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function data(array $override = []): array
    {
        return [
            'name' => 'Budi',
            'nis' => '20261001',
            'nisn' => null,
            'email' => null,
            'nik' => null,
            'jenis_kelamin' => 'laki-laki',
            'tempat_lahir' => 'Kendari',
            'tanggal_lahir' => '2011-05-01',
            'agama' => 'Islam',
            'alamat' => null,
            'no_hp' => null,
            'foto' => null,
            'tanggal_masuk' => null,
            'asal_sekolah' => null,
            ...$override,
        ];
    }

    /**
     * The edit payload of $siswa as it is stored, with $override applied.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function editData(Siswa $siswa, array $override = []): array
    {
        $siswa = $siswa->fresh();
        $user = $siswa->user;

        return [
            'name' => $user->name,
            'nis' => $siswa->nis,
            'nisn' => $siswa->nisn,
            'email' => $user->email,
            'nik' => null,
            'hapus_nik' => false,
            'jenis_kelamin' => $user->jenis_kelamin?->value,
            'tempat_lahir' => $user->tempat_lahir,
            'tanggal_lahir' => $user->tanggal_lahir?->toDateString(),
            'agama' => $user->agama?->value,
            'alamat' => $user->alamat,
            'no_hp' => $user->no_hp,
            'foto' => $user->foto,
            'tanggal_masuk' => $siswa->tanggal_masuk?->toDateString(),
            'asal_sekolah' => $siswa->asal_sekolah,
            ...$override,
        ];
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
