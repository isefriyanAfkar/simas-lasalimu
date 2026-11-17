<?php

namespace Tests\Feature\Domains\People\Services;

use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\People\Enums\JenisPtk;
use App\Domains\People\Enums\StatusPtk;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Models\StatusKepegawaian;
use App\Domains\People\Services\PtkService;
use App\Domains\People\Services\StatusKepegawaianService;
use Closure;
use Database\Seeders\PeranDanIzinSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Creating and changing staff (PTK): biodata through UserService's rules,
 * the PTK fields, roles, and who may link which account. Status changes are
 * in PtkStatusTest.
 */
class PtkServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string NIK = '7404012345670001';

    private const string NIP = '198001012005012001';

    private const string NUPTK = '1234567890123456';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PeranDanIzinSeeder::class);
    }

    public function test_creates_a_staff_member_with_a_new_account(): void
    {
        $operator = User::factory()->operator()->create();

        $ptk = $this->service()->create($operator, $this->data([
            'name' => '  Hafida ',
            'username' => ' Hafida ',
            'nik' => self::NIK,
            'gelar_depan' => ' Dra. ',
            'gelar_belakang' => ' S.Pd.,  Gr. ',
            'kode' => ' g01 ',
            'nip' => self::NIP,
            'nuptk' => self::NUPTK,
            'sertifikasi' => true,
            'tahun_sertifikasi' => '2015',
        ]))->fresh();

        $user = $ptk->user;
        $this->assertSame('Hafida', $user->name);
        $this->assertSame('hafida', $user->username);
        $this->assertTrue($user->status_aktif);
        $this->assertSame(self::NIK, $user->nik);
        $this->assertSame(JenisKelamin::Perempuan, $user->jenis_kelamin);
        $this->assertSame(['guru'], $user->getRoleNames()->all());
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));

        $this->assertSame(JenisPtk::Guru, $ptk->jenis_ptk);
        $this->assertSame(StatusPtk::Aktif, $ptk->status);
        $this->assertSame('Dra.', $ptk->gelar_depan);
        $this->assertSame('S.Pd., Gr.', $ptk->gelar_belakang);
        $this->assertSame('G01', $ptk->kode);
        $this->assertSame(self::NIP, $ptk->nip);
        $this->assertSame(self::NUPTK, $ptk->nuptk);
        $this->assertTrue($ptk->sertifikasi);
        $this->assertSame(2015, $ptk->tahun_sertifikasi);
        $this->assertNull($ptk->tanggal_keluar);
        $this->assertSame('Dra. Hafida, S.Pd., Gr.', $ptk->nama_lengkap);
    }

    public function test_a_new_account_gets_a_random_password_that_is_never_returned(): void
    {
        $operator = User::factory()->operator()->create();

        $pertama = $this->service()->create($operator, $this->data(['username' => 'satu']))->user->fresh();
        $kedua = $this->service()->create($operator, $this->data(['username' => 'dua', 'nik' => null]))->user->fresh();

        $this->assertNotEmpty($pertama->getAuthPassword());
        $this->assertNotSame($pertama->getAuthPassword(), $kedua->getAuthPassword());
        $this->assertFalse(password_verify('', $pertama->getAuthPassword()));
        $this->assertFalse(password_verify('password', $pertama->getAuthPassword()));
        $this->assertFalse(password_verify('satu', $pertama->getAuthPassword()));
    }

    public function test_a_tendik_gets_the_tendik_role(): void
    {
        $ptk = $this->service()->create(User::factory()->operator()->create(), $this->data(['jenis_ptk' => 'tendik']));

        $this->assertSame(['tendik'], $ptk->user->fresh()->getRoleNames()->all());
    }

    public function test_empty_optional_fields_are_stored_as_null_and_may_repeat(): void
    {
        $operator = User::factory()->operator()->create();
        $kosong = ['nip' => ' ', 'nuptk' => '', 'kode' => '', 'gelar_depan' => '', 'gelar_belakang' => null, 'nik' => null];

        $pertama = $this->service()->create($operator, $this->data(['username' => 'satu', ...$kosong]))->fresh();
        $kedua = $this->service()->create($operator, $this->data(['username' => 'dua', ...$kosong]))->fresh();

        foreach ([$pertama, $kedua] as $ptk) {
            $this->assertNull($ptk->nip);
            $this->assertNull($ptk->nuptk);
            $this->assertNull($ptk->kode);
            $this->assertNull($ptk->gelar_depan);
            $this->assertNull($ptk->gelar_belakang);
            $this->assertFalse($ptk->sertifikasi);
            $this->assertNull($ptk->tahun_sertifikasi);
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidData(): array
    {
        return [
            'mode akun tidak dikenal' => [['akun' => 'lain'], 'akun'],
            'jenis kelamin kosong' => [['jenis_kelamin' => null], 'jenis_kelamin'],
            'jenis kelamin tidak dikenal' => [['jenis_kelamin' => 'x'], 'jenis_kelamin'],
            'nama kosong' => [['name' => ' '], 'name'],
            'username kosong' => [['username' => ''], 'username'],
            'NIK 15 digit' => [['nik' => '740401234567000'], 'nik'],
            'jenis PTK kosong' => [['jenis_ptk' => null], 'jenis_ptk'],
            'jenis PTK tidak dikenal' => [['jenis_ptk' => 'kepala'], 'jenis_ptk'],
            'status kepegawaian kosong' => [['status_kepegawaian_id' => null], 'status_kepegawaian_id'],
            'status kepegawaian tidak ada' => [['status_kepegawaian_id' => 999999], 'status_kepegawaian_id'],
            'NIP 17 angka' => [['nip' => '19800101200501200'], 'nip'],
            'NIP 19 angka' => [['nip' => '1980010120050120011'], 'nip'],
            'NIP berspasi' => [['nip' => '19800101 200501 2 001'], 'nip'],
            'NUPTK 15 angka' => [['nuptk' => '123456789012345'], 'nuptk'],
            'NUPTK 17 angka' => [['nuptk' => '12345678901234567'], 'nuptk'],
            'NUPTK berhuruf' => [['nuptk' => '123456789012345A'], 'nuptk'],
            'kode 11 karakter' => [['kode' => 'ABCDE123456'], 'kode'],
            'kode bertanda hubung' => [['kode' => 'G-01'], 'kode'],
            'kode berspasi' => [['kode' => 'G 01'], 'kode'],
            'gelar depan lebih dari 50' => [['gelar_depan' => str_repeat('a', 51)], 'gelar_depan'],
            'gelar belakang lebih dari 100' => [['gelar_belakang' => str_repeat('a', 101)], 'gelar_belakang'],
            'tahun sertifikasi 3 angka' => [['sertifikasi' => true, 'tahun_sertifikasi' => '999'], 'tahun_sertifikasi'],
            'tahun sertifikasi 5 angka' => [['sertifikasi' => true, 'tahun_sertifikasi' => '20150'], 'tahun_sertifikasi'],
            'tahun sertifikasi berhuruf' => [['sertifikasi' => true, 'tahun_sertifikasi' => '20x5'], 'tahun_sertifikasi'],
            'tahun sertifikasi tanpa sertifikasi' => [['sertifikasi' => false, 'tahun_sertifikasi' => '2015'], 'tahun_sertifikasi'],
            'sertifikasi bukan ya/tidak' => [['sertifikasi' => 'mungkin'], 'sertifikasi'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidData')]
    public function test_create_rejects_invalid_data(array $override, string $field): void
    {
        $operator = User::factory()->operator()->create();

        $this->assertValidationError($field, fn () => $this->service()->create($operator, $this->data($override)));

        $this->assertSame(0, Ptk::query()->count());
        $this->assertDatabaseMissing('users', ['username' => 'hafida']);
    }

    public function test_errors_of_the_account_and_of_the_staff_fields_are_reported_together(): void
    {
        $operator = User::factory()->operator()->create();

        try {
            $this->service()->create($operator, $this->data(['jenis_kelamin' => null, 'nip' => '123']));
            $this->fail('Invalid data was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('jenis_kelamin', $exception->errors());
            $this->assertArrayHasKey('nip', $exception->errors());
        }
    }

    public function test_the_certification_year_may_be_this_year_but_not_next_year(): void
    {
        $operator = User::factory()->operator()->create();
        $tahunIni = (string) now()->year;
        $tahunDepan = (string) (now()->year + 1);

        $this->assertValidationError('tahun_sertifikasi', fn () => $this->service()->create($operator, $this->data(['sertifikasi' => true, 'tahun_sertifikasi' => $tahunDepan])));

        $ptk = $this->service()->create($operator, $this->data(['sertifikasi' => true, 'tahun_sertifikasi' => $tahunIni]));

        $this->assertSame(now()->year, $ptk->fresh()->tahun_sertifikasi);
    }

    public function test_codes_of_1_and_10_characters_are_accepted(): void
    {
        $operator = User::factory()->operator()->create();

        $this->service()->create($operator, $this->data(['username' => 'satu', 'nik' => null, 'kode' => 'a']));
        $this->service()->create($operator, $this->data(['username' => 'dua', 'nik' => null, 'kode' => 'abcde12345']));

        $this->assertSame(['A', 'ABCDE12345'], Ptk::query()->orderBy('kode')->pluck('kode')->all());
    }

    public function test_an_inactive_employment_status_cannot_be_chosen_for_a_new_staff_member(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $gtt = StatusKepegawaian::query()->where('nama', 'GTT')->sole();
        app(StatusKepegawaianService::class)->deactivate($superAdmin, $gtt);

        $this->assertValidationError('status_kepegawaian_id', fn () => $this->service()->create($superAdmin, $this->data(['status_kepegawaian_id' => $gtt->id])));
        $this->assertSame(0, Ptk::query()->count());
    }

    public function test_the_options_hold_active_statuses_plus_the_current_one(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $gtt = StatusKepegawaian::query()->where('nama', 'GTT')->sole();
        app(StatusKepegawaianService::class)->deactivate($superAdmin, $gtt);

        $this->assertSame(['PNS', 'PPPK', 'PTT', 'GTBPNS'], array_values(app(StatusKepegawaianService::class)->opsiUntukDipilih(null)));
        $this->assertSame(['PNS', 'PPPK', 'GTT', 'PTT', 'GTBPNS'], array_values(app(StatusKepegawaianService::class)->opsiUntukDipilih($gtt->id)));
    }

    public function test_update_may_keep_an_inactive_employment_status_but_not_switch_to_another(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $gtt = StatusKepegawaian::query()->where('nama', 'GTT')->sole();
        $ptt = StatusKepegawaian::query()->where('nama', 'PTT')->sole();
        $ptk = $this->service()->create($superAdmin, $this->data(['status_kepegawaian_id' => $gtt->id]));
        app(StatusKepegawaianService::class)->deactivate($superAdmin, $gtt);
        app(StatusKepegawaianService::class)->deactivate($superAdmin, $ptt);

        $this->service()->update($superAdmin, $ptk, $this->editData($ptk, ['kode' => 'G02']));
        $this->assertSame('G02', $ptk->fresh()->kode);

        $this->assertValidationError('status_kepegawaian_id', fn () => $this->service()->update($superAdmin, $ptk->fresh(), $this->editData($ptk, ['status_kepegawaian_id' => $ptt->id])));
        $this->assertSame($gtt->id, $ptk->fresh()->status_kepegawaian_id);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function uniqueFields(): array
    {
        return [
            'NIP' => ['nip', self::NIP, self::NIP],
            'NUPTK' => ['nuptk', self::NUPTK, self::NUPTK],
            'kode' => ['kode', 'G01', 'g01'],
        ];
    }

    #[DataProvider('uniqueFields')]
    public function test_a_taken_value_is_rejected_with_the_holders_full_name(string $field, string $stored, string $typed): void
    {
        $operator = User::factory()->operator()->create();
        $this->service()->create($operator, $this->data(['username' => 'hafida', $field => $stored, 'gelar_depan' => 'Dra.']));

        try {
            $this->service()->create($operator, $this->data(['username' => 'budi', 'nik' => null, $field => $typed]));
            $this->fail('A taken value was accepted.');
        } catch (ValidationException $exception) {
            $label = ['nip' => 'NIP', 'nuptk' => 'NUPTK', 'kode' => 'Kode'][$field];
            $this->assertSame(["{$label} ini sudah dipakai oleh Dra. Hafida."], $exception->errors()[$field]);
        }

        $this->assertSame(1, Ptk::query()->count());
    }

    #[DataProvider('uniqueFields')]
    public function test_update_may_keep_its_own_unique_values_but_not_take_another(string $field, string $stored, string $typed): void
    {
        $operator = User::factory()->operator()->create();
        $hafida = $this->service()->create($operator, $this->data(['username' => 'hafida', $field => $stored]));
        $budi = $this->service()->create($operator, $this->data(['username' => 'budi', 'nik' => null]));

        $this->service()->update($operator, $hafida, $this->editData($hafida, [$field => $typed]));

        $this->assertValidationError($field, fn () => $this->service()->update($operator, $budi, $this->editData($budi, [$field => $typed])));
        $this->assertNull($budi->fresh()->{$field});
    }

    #[DataProvider('uniqueFields')]
    public function test_a_concurrent_create_with_the_same_value_ends_as_a_field_error(string $field, string $stored, string $typed): void
    {
        $operator = User::factory()->operator()->create();
        $lain = User::factory()->create();
        $pns = StatusKepegawaian::query()->where('nama', 'PNS')->value('id');

        Ptk::creating(function (Ptk $ptk) use ($field, $lain, $pns): void {
            DB::table('ptk')->insert([
                'user_id' => $lain->id,
                'jenis_ptk' => 'guru',
                'status_kepegawaian_id' => $pns,
                $field => $ptk->{$field},
                'sertifikasi' => false,
                'status' => 'aktif',
            ]);
        });

        $this->assertValidationError($field, fn () => $this->service()->create($operator, $this->data([$field => $typed])));

        $this->assertSame(0, Ptk::query()->count());
        $this->assertDatabaseMissing('users', ['username' => 'hafida']);
    }

    public function test_a_staff_record_may_be_made_for_an_existing_operator_without_losing_the_operator_role(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create(['name' => 'Siti', 'username' => 'siti']);
        $kataSandi = $operator->getAuthPassword();

        $ptk = $this->service()->create($superAdmin, $this->data([
            'akun' => 'ada',
            'user_id' => $operator->id,
            'name' => 'Siti Aminah',
            'username' => 'siti',
            'nik' => null,
        ]));

        $operator = $operator->fresh();
        $this->assertTrue($ptk->user->is($operator));
        $this->assertEqualsCanonicalizing(['operator', 'guru'], $operator->getRoleNames()->all());
        $this->assertSame('Siti Aminah', $operator->name);
        $this->assertSame(JenisKelamin::Perempuan, $operator->jenis_kelamin);
        $this->assertSame($kataSandi, $operator->getAuthPassword());
        $this->assertTrue($operator->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_operator_may_link_an_account_without_a_panel_role(): void
    {
        $operator = User::factory()->operator()->create();
        $user = User::factory()->create(['username' => 'budi']);

        $ptk = $this->service()->create($operator, $this->data(['akun' => 'ada', 'user_id' => $user->id, 'username' => 'budi', 'nik' => null]));

        $this->assertTrue($ptk->user->is($user));
        $this->assertSame(['guru'], $user->fresh()->getRoleNames()->all());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unmanageableAccounts(): array
    {
        return [
            'super_admin' => ['superAdmin'],
            'operator lain' => ['operator'],
        ];
    }

    #[DataProvider('unmanageableAccounts')]
    public function test_operator_may_not_link_a_super_admin_or_another_operator(string $state): void
    {
        $operator = User::factory()->operator()->create();
        $akun = User::factory()->{$state}()->create();

        $this->assertValidationError('user_id', fn () => $this->service()->create($operator, $this->data([
            'akun' => 'ada',
            'user_id' => $akun->id,
            'username' => $akun->username,
            'nik' => null,
        ])));

        $this->assertSame(0, Ptk::query()->count());
        $this->assertFalse($akun->fresh()->hasRole(Peran::Guru->value));
    }

    public function test_an_account_that_already_has_a_staff_record_cannot_be_linked_again(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $ptk = $this->service()->create($superAdmin, $this->data(['gelar_depan' => 'Dra.']));

        try {
            $this->service()->create($superAdmin, $this->data([
                'akun' => 'ada',
                'user_id' => $ptk->user_id,
                'username' => 'hafida',
                'jenis_ptk' => 'tendik',
            ]));
            $this->fail('An account was linked twice.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Pengguna ini sudah terdaftar sebagai guru/staf: Dra. Hafida.'], $exception->errors()['user_id']);
        }

        $this->assertSame(1, Ptk::query()->count());
        $this->assertSame(['guru'], $ptk->user->fresh()->getRoleNames()->all());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function missingAccounts(): array
    {
        return [
            'tidak dipilih' => [['user_id' => null]],
            'tidak ada' => [['user_id' => 999999]],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('missingAccounts')]
    public function test_linking_needs_an_existing_account(array $override): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertValidationError('user_id', fn () => $this->service()->create($superAdmin, $this->data(['akun' => 'ada', ...$override])));
    }

    public function test_update_changes_the_biodata_and_the_staff_fields(): void
    {
        $operator = User::factory()->operator()->create();
        $ptk = $this->service()->create($operator, $this->data(['nik' => self::NIK]));

        $this->service()->update($operator, $ptk, $this->editData($ptk, [
            'name' => 'Hafida Sari',
            'tempat_lahir' => 'Baubau',
            'gelar_belakang' => 'M.Pd.',
            'nip' => self::NIP,
            'status' => 'pensiun',
        ]));

        $ptk = $ptk->fresh();
        $this->assertSame('Hafida Sari, M.Pd.', $ptk->nama_lengkap);
        $this->assertSame('Baubau', $ptk->user->tempat_lahir);
        $this->assertSame(self::NIK, $ptk->user->nik);
        $this->assertSame(self::NIP, $ptk->nip);
        $this->assertSame(StatusPtk::Aktif, $ptk->status);
    }

    public function test_changing_the_kind_swaps_guru_and_tendik_and_keeps_other_roles(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create(['username' => 'siti']);
        $ptk = $this->service()->create($superAdmin, $this->data(['akun' => 'ada', 'user_id' => $operator->id, 'username' => 'siti', 'nik' => null]));

        $this->service()->update($superAdmin, $ptk, $this->editData($ptk, ['jenis_ptk' => 'tendik']));

        $this->assertSame(JenisPtk::Tendik, $ptk->fresh()->jenis_ptk);
        $this->assertEqualsCanonicalizing(['operator', 'tendik'], $operator->fresh()->getRoleNames()->all());
        $log = Activity::query()->where('event', 'role_berubah')->latest('id')->firstOrFail();
        $this->assertSame(['peran_ptk' => 'tendik'], $log->attribute_changes['attributes']);
        $this->assertSame(['peran_ptk' => 'guru'], $log->attribute_changes['old']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function protectedAccounts(): array
    {
        return self::unmanageableAccounts();
    }

    #[DataProvider('protectedAccounts')]
    public function test_operator_may_not_change_the_staff_record_of_a_super_admin_or_another_operator(string $state): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $akun = User::factory()->{$state}()->create();
        $ptk = $this->service()->create($superAdmin, $this->data(['akun' => 'ada', 'user_id' => $akun->id, 'username' => $akun->username, 'nik' => null]));
        $operator = User::factory()->operator()->create();

        try {
            $this->service()->update($operator, $ptk, $this->editData($ptk, ['kode' => 'X1']));
            $this->fail('The operator changed a protected staff record.');
        } catch (PelanggaranAturanPengguna $exception) {
            $this->assertSame(PelanggaranAturanPengguna::tidakBolehMengelola()->getMessage(), $exception->getMessage());
        }

        $this->assertNull($ptk->fresh()->kode);
    }

    public function test_create_and_update_are_logged_without_the_nik(): void
    {
        $operator = User::factory()->operator()->create();
        $ptk = $this->service()->create($operator, $this->data(['nik' => self::NIK, 'kode' => 'G01']));
        $this->service()->update($operator, $ptk, $this->editData($ptk, ['kode' => 'G02']));

        $created = Activity::query()->where('event', 'created')->where('subject_type', $ptk->getMorphClass())->sole();
        $this->assertSame('kepegawaian', $created->log_name);
        $this->assertSame('Data guru/staf dibuat', $created->description);
        $this->assertTrue($created->causer->is($operator));
        $this->assertSame('G01', $created->attribute_changes['attributes']['kode']);
        $this->assertArrayNotHasKey('status', $created->attribute_changes['attributes']);

        $updated = Activity::query()->where('event', 'updated')->where('subject_type', $ptk->getMorphClass())->sole();
        $this->assertSame(['kode' => 'G02'], $updated->attribute_changes['attributes']);

        $this->assertTrue(Activity::query()->where('log_name', 'pengguna')->where('event', 'dibuat')->exists());
        foreach (Activity::query()->get() as $activity) {
            $this->assertStringNotContainsString(self::NIK, json_encode($activity->toArray()));
        }
    }

    private function service(): PtkService
    {
        return app(PtkService::class);
    }

    /**
     * A valid "new account" payload, as the form sends it.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function data(array $override = []): array
    {
        return [
            'akun' => 'baru',
            'user_id' => null,
            'name' => 'Hafida',
            'username' => 'hafida',
            'email' => null,
            'nik' => self::NIK,
            'jenis_kelamin' => 'perempuan',
            'tempat_lahir' => 'Kendari',
            'tanggal_lahir' => '1980-01-01',
            'agama' => 'Islam',
            'alamat' => null,
            'no_hp' => null,
            'foto' => null,
            'jenis_ptk' => 'guru',
            'status_kepegawaian_id' => StatusKepegawaian::query()->where('nama', 'PNS')->value('id'),
            'nip' => null,
            'nuptk' => null,
            'gelar_depan' => null,
            'gelar_belakang' => null,
            'kode' => null,
            'sertifikasi' => false,
            'tahun_sertifikasi' => null,
            ...$override,
        ];
    }

    /**
     * The edit payload of $ptk as it is stored, with $override applied.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function editData(Ptk $ptk, array $override = []): array
    {
        $ptk = $ptk->fresh();
        $user = $ptk->user;

        return [
            'name' => $user->name,
            'username' => $user->username,
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
            'jenis_ptk' => $ptk->jenis_ptk->value,
            'status_kepegawaian_id' => $ptk->status_kepegawaian_id,
            'nip' => $ptk->nip,
            'nuptk' => $ptk->nuptk,
            'gelar_depan' => $ptk->gelar_depan,
            'gelar_belakang' => $ptk->gelar_belakang,
            'kode' => $ptk->kode,
            'sertifikasi' => $ptk->sertifikasi,
            'tahun_sertifikasi' => $ptk->tahun_sertifikasi,
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
