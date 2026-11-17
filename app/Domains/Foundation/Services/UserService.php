<?php

namespace App\Domains\Foundation\Services;

use App\Domains\Foundation\Enums\Agama;
use App\Domains\Foundation\Enums\AksiPengguna;
use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use BackedEnum;
use Carbon\CarbonInterface;
use Closure;
use finfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * The only place that writes user accounts. Every method takes the acting
 * user ($pelaku) so the user management rules can be enforced here.
 *
 * Rules:
 * - a user cannot deactivate their own account
 * - the last active super_admin cannot be deactivated or lose the super_admin role
 * - a non super_admin cannot change, (de)activate or reset the password of a
 *   super_admin or of another operator
 * - a non super_admin cannot grant or take away the super_admin or operator role
 * - create() and update() manage only the panel role: peran is super_admin,
 *   operator, or null (no panel access), and the key must be sent. The staff
 *   roles guru and tendik and the student role siswa are never added or
 *   removed here
 * - the username of a student account is its NIS and only changes with the
 *   NIS (SiswaService); update() rejects any other change
 *
 * create() and update() validate their input here (see validateAccountData),
 * whatever the caller already checked. Every save that checks username or
 * email uniqueness does so inside a transaction that first locks the user
 * rows, and KeunikanDatabase turns a unique index violation that still
 * happens into a field error.
 *
 * Biodata rules (all fields optional):
 * - nik is exactly 16 digits and unique; it is stored encrypted (APP_KEY)
 *   with its HMAC in nik_hash (PenjagaNik, NIK_HMAC_KEY), and uniqueness is
 *   checked on nik_hash. On update an empty nik keeps the stored one;
 *   hapus_nik clears both columns
 * - jenis_kelamin and agama are values of their enums
 * - tanggal_lahir is a Y-m-d date before today
 * - no_hp is normalized to 62xxx (08xx and +62xx are accepted; spaces,
 *   dashes, dots and brackets are dropped), has 10 to 15 digits and no 0
 *   right after 62
 * - foto is the current path, or a JPG, PNG or WebP of at most 1 MB inside
 *   FOTO_DIRECTORY on the private FOTO_DISK that no other user has; a
 *   replaced or removed photo is deleted after the transaction commits, and
 *   only inside FOTO_DIRECTORY
 * On update a biodata key that is not sent keeps its stored value.
 *
 * Every successful write is recorded in the activity log (log "pengguna") in
 * the same transaction. Only the attributes in LOGGED_ATTRIBUTES (and the role
 * name) are ever written to the log with their values, so passwords and
 * remember tokens never reach it. nik and foto appear only as a marker word
 * (diisi/diubah/dihapus, diunggah/diganti/dihapus), never as a value or path.
 * Revealing a NIK (tampilkanNik) and searching by NIK (cariNik) are logged
 * too, without the NIK.
 *
 * Password contract: the two password paths hand over different forms, and
 * both are correct because User casts `password` as `hashed`, which hashes a
 * plaintext value and stores an already-hashed value unchanged.
 * - resetPassword() receives plaintext from the admin's reset form.
 * - updateOwnProfile() receives a hash: Filament's profile password field
 *   already applies Hash::make(), and Filament also stores that hash in the
 *   session (password_hash_*) to keep the user signed in.
 * Keep the `hashed` cast on User: without it resetPassword() would store
 * plaintext. Never hash again in this service: updateOwnProfile() would then
 * store a hash of a hash and the user could no longer log in. Both paths are
 * covered by tests that log in with the new password.
 */
class UserService
{
    private const string LOG_NAME = 'pengguna';

    /**
     * Saves that check username/email uniqueness lock the user rows first;
     * two such saves can deadlock on the gap lock, and the retry then sees
     * the other row and fails validation.
     */
    private const int ATTEMPTS = 3;

    /**
     * Unique indexes whose violation (a save that slipped past the unique
     * rule) becomes a field error instead of a server error.
     *
     * @var array<string, array{string, string}>
     */
    private const array INDEKS_UNIK = [
        'users_username_unique' => ['username', 'Username ini baru saja dipakai pengguna lain. Periksa lagi, lalu simpan.'],
        'users_email_unique' => ['email', 'Email ini baru saja dipakai pengguna lain. Periksa lagi, lalu simpan.'],
        'users_nik_hash_unique' => ['nik', 'NIK ini baru saja didaftarkan untuk pengguna lain. Periksa lagi, lalu simpan.'],
    ];

    /**
     * The private disk (storage/app/private): photos are served only through
     * the pengguna.foto route, which checks UserPolicy::viewFoto.
     */
    public const string FOTO_DISK = 'local';

    public const string FOTO_DIRECTORY = 'foto-pengguna';

    public const int FOTO_MAKS_KB = 1024;

    /**
     * @var list<string>
     */
    public const array FOTO_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public const string PESAN_FOTO = 'Foto tidak valid. Unggah JPG, PNG, atau WebP paling besar 1 MB.';

    public const string PESAN_KUNCI_NIK = 'NIK belum bisa disimpan: kunci NIK belum diatur di server.';

    public const string PESAN_USERNAME_SISWA = 'Username siswa mengikuti NIS. Ubah NIS di layar Siswa.';

    public const string PESAN_KUNCI_NIK_CARI = 'NIK belum bisa dicari: kunci NIK belum diatur di server.';

    /**
     * Logged with their old and new values. nik and foto are deliberately
     * absent: they are logged as marker words only.
     *
     * @var list<string>
     */
    private const array LOGGED_ATTRIBUTES = [
        'name', 'username', 'email', 'status_aktif',
        ...self::LOGGED_BIODATA,
    ];

    /**
     * @var list<string>
     */
    private const array LOGGED_BIODATA = ['jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'agama', 'alamat', 'no_hp'];

    /**
     * Biodata keys create() and update() accept, besides nik and hapus_nik.
     *
     * @var list<string>
     */
    private const array BIODATA_FIELDS = [...self::LOGGED_BIODATA, 'foto'];

    /**
     * The only biodata a user may change on their own profile.
     *
     * @var list<string>
     */
    private const array PROFILE_BIODATA_FIELDS = ['no_hp', 'alamat', 'foto'];

    /**
     * @var array<string, string>
     */
    private const array BIODATA_MESSAGES = [
        'nik.regex' => 'NIK harus terdiri dari tepat 16 digit angka.',
        'no_hp.regex' => 'Nomor HP harus diawali 08, 62, atau +62 dan berisi 10 sampai 15 angka.',
        'tanggal_lahir.before' => 'Tanggal lahir harus sebelum hari ini.',
    ];

    /**
     * @var array<string, string>
     */
    private const array BIODATA_ATTRIBUTES = [
        'nik' => 'NIK',
        'jenis_kelamin' => 'jenis kelamin',
        'tempat_lahir' => 'tempat lahir',
        'tanggal_lahir' => 'tanggal lahir',
        'no_hp' => 'nomor HP',
    ];

    /**
     * Input is validated before the role check, because the role must be a
     * known Peran first. So invalid input (for example a taken username) is
     * reported before a role the actor may not grant. This reveals nothing:
     * the actor sees those usernames anyway. update() checks whether the actor
     * may manage the user first, since there the target already exists.
     *
     * @param  array{name: string, username: string, email?: string|null, password: string, peran: string|null, nik?: string|null, jenis_kelamin?: string|null, tempat_lahir?: string|null, tanggal_lahir?: string|null, agama?: string|null, alamat?: string|null, no_hp?: string|null, foto?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(User $pelaku, array $data): User
    {
        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): User => DB::transaction(function () use ($pelaku, $data): User {
            $data = $this->validateAccountData($data, null);
            $peran = $data['peran'] === null ? null : Peran::from($data['peran']);

            if ($peran !== null) {
                $this->ensureCanGrantRole($pelaku, $peran);
            }

            $user = new User([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
                'status_aktif' => true,
                ...Arr::only($data, self::BIODATA_FIELDS),
            ]);
            $this->applyNik($user, $data);
            $user->save();

            $this->aturPeranPanel($user, $peran);

            $this->log(AksiPengguna::Dibuat, $pelaku, $user, [
                'attributes' => [...$this->createdAttributes($user), 'peran' => $peran?->value],
            ]);

            return $user;
        }, self::ATTEMPTS));
    }

    /**
     * Biodata keys that are not sent keep their stored value; an empty nik
     * keeps the stored NIK and hapus_nik clears it.
     *
     * @param  array{name: string, username: string, email?: string|null, peran: string|null, nik?: string|null, hapus_nik?: bool, jenis_kelamin?: string|null, tempat_lahir?: string|null, tanggal_lahir?: string|null, agama?: string|null, alamat?: string|null, no_hp?: string|null, foto?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function update(User $pelaku, User $user, array $data): User
    {
        $this->ensureCanManage($pelaku, $user);

        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): User => DB::transaction(function () use ($pelaku, $user, $data): User {
            $data = $this->validateAccountData($data, $user);
            $peran = $data['peran'] === null ? null : Peran::from($data['peran']);

            if ($data['username'] !== $user->getOriginal('username') && $user->siswa()->exists()) {
                throw ValidationException::withMessages(['username' => self::PESAN_USERNAME_SISWA]);
            }

            // The rows are locked now: read the roles again instead of the
            // relation loaded with the page, which another save may have changed.
            $user->unsetRelation('roles');
            $peranLama = $user->peranPanel();
            $semuaPeranPanelLama = $user->semuaPeranPanel();

            if ($peran !== null && ! in_array($peran, $semuaPeranPanelLama, true)) {
                $this->ensureCanGrantRole($pelaku, $peran);
            }

            foreach ($semuaPeranPanelLama as $lama) {
                if ($lama !== $peran && ! $this->canGrantRole($pelaku, $lama)) {
                    throw PelanggaranAturanPengguna::tidakBolehMencabutPeran($lama->value);
                }
            }

            if ($peran !== Peran::SuperAdmin) {
                $this->ensureNotLastActiveSuperAdmin($user);
            }

            $before = $this->snapshot($user);
            $nikHashBefore = $user->nik_hash;
            $fotoBefore = $user->foto;

            $user->fill([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                ...Arr::only($data, self::BIODATA_FIELDS),
            ]);
            $this->applyNik($user, $data);
            $user->save();

            $peranPanelBerubah = $semuaPeranPanelLama !== ($peran === null ? [] : [$peran]);
            $this->aturPeranPanel($user, $peran);

            $changes = $this->diff($before, $this->snapshot($user), $this->markers($nikHashBefore, $user->nik_hash, $fotoBefore, $user->foto));
            $this->deleteReplacedPhotoAfterCommit($fotoBefore, $user->foto);

            if ($changes !== null) {
                $this->log(AksiPengguna::Diubah, $pelaku, $user, $changes);
            }

            if ($peranPanelBerubah) {
                $this->log(AksiPengguna::RoleBerubah, $pelaku, $user, [
                    'attributes' => ['peran' => $peran?->value],
                    'old' => ['peran' => $peranLama?->value],
                ]);
            }

            return $user;
        }, self::ATTEMPTS));
    }

    public function activate(User $pelaku, User $user): User
    {
        $this->ensureCanManage($pelaku, $user);

        return DB::transaction(function () use ($pelaku, $user): User {
            $this->changeStatus($pelaku, $user, true);

            return $user;
        });
    }

    public function deactivate(User $pelaku, User $user): User
    {
        $this->ensureCanManage($pelaku, $user);

        return DB::transaction(function () use ($pelaku, $user): User {
            $this->ensureNotLastActiveSuperAdmin($user);

            if ($pelaku->is($user)) {
                throw PelanggaranAturanPengguna::menonaktifkanDiriSendiri();
            }

            $this->changeStatus($pelaku, $user, false);

            return $user;
        });
    }

    /**
     * @param  string  $kataSandiBaru  plaintext; hashed by the model's `hashed` cast
     */
    public function resetPassword(User $pelaku, User $user, string $kataSandiBaru): User
    {
        $this->ensureCanManage($pelaku, $user);

        return DB::transaction(function () use ($pelaku, $user, $kataSandiBaru): User {
            $user->update(['password' => $kataSandiBaru]);

            $this->log(AksiPengguna::KataSandiDiaturUlang, $pelaku, $user);

            return $user;
        });
    }

    /**
     * A signed-in user changing their own name, phone number, address and
     * photo and, optionally, password. Every other key (such as username, nik
     * or agama) is ignored, also when a tampered request sends it. A biodata
     * key that is not sent keeps its stored value.
     *
     * @param  array{name: string, password?: string|null, no_hp?: string|null, alamat?: string|null, foto?: string|null}  $data  password may be plaintext or an already-hashed value (see class docblock)
     *
     * @throws ValidationException
     */
    public function updateOwnProfile(User $user, array $data): User
    {
        $biodata = $this->prepareBiodata($data, self::PROFILE_BIODATA_FIELDS);

        /** @var array{no_hp?: string|null, alamat?: string|null, foto?: string|null} $biodata */
        $biodata = Validator::make(
            $biodata,
            Arr::only($this->biodataRules($user), array_keys($biodata)),
            self::BIODATA_MESSAGES,
            self::BIODATA_ATTRIBUTES,
        )->validate();

        return DB::transaction(function () use ($user, $data, $biodata): User {
            $fields = ['name', 'no_hp', 'alamat'];
            $before = $user->only($fields);
            $fotoBefore = $user->foto;

            $user->update(['name' => $data['name'], ...$biodata]);

            $changes = $this->diff($before, $user->only($fields), $this->markers(null, null, $fotoBefore, $user->foto));
            $this->deleteReplacedPhotoAfterCommit($fotoBefore, $user->foto);

            if ($changes !== null) {
                $this->log(AksiPengguna::ProfilDiubah, $user, $user, $changes);
            }

            if (filled($data['password'] ?? null)) {
                $user->update(['password' => $data['password']]);

                $this->log(AksiPengguna::KataSandiDiubahSendiri, $user, $user);
            }

            return $user;
        });
    }

    /**
     * Find the user whose NIK is $nik, through nik_hash, for someone holding
     * ViewNik:User (UserPolicy searchNik). A match the searcher may not
     * reveal (UserPolicy viewNik) is reported as no match, so the search
     * never tells who owns a NIK beyond what "Tampilkan NIK" would. Every
     * search is logged ("NIK dicari", subject = the user returned, if any);
     * the log never holds the NIK or its hash.
     *
     * @throws AuthorizationException
     * @throws ValidationException when $nik is not 16 digits or the NIK key is not set
     */
    public function cariNik(User $pelaku, string $nik): ?User
    {
        if (! $pelaku->can('searchNik', User::class)) {
            throw new AuthorizationException('Anda tidak berizin mencari NIK.');
        }

        $nik = trim($nik);

        Validator::make(['nik' => $nik], ['nik' => ['required', 'regex:/^\d{16}$/']], self::BIODATA_MESSAGES, self::BIODATA_ATTRIBUTES)->validate();

        try {
            $user = User::query()->denganNik($nik)->first();
        } catch (KunciNikTidakSiap) {
            throw ValidationException::withMessages(['nik' => self::PESAN_KUNCI_NIK_CARI]);
        }

        if ($user !== null && ! $pelaku->can('viewNik', $user)) {
            $user = null;
        }

        DB::transaction(function () use ($pelaku, $user): void {
            $logger = activity(self::LOG_NAME)
                ->causedBy($pelaku)
                ->event(AksiPengguna::NikDicari->value);

            if ($user !== null) {
                $logger->performedOn($user);
            }

            $logger->log(AksiPengguna::NikDicari->label());
        });

        return $user;
    }

    /**
     * Return the user's NIK in full to someone allowed to see it (UserPolicy
     * viewNik: permission ViewNik:User and allowed to manage that user) and
     * log who saw whose NIK, when. The log entry never holds the NIK.
     *
     * @throws AuthorizationException
     */
    public function tampilkanNik(User $pelaku, User $user): string
    {
        if (! $pelaku->can('viewNik', $user)) {
            throw new AuthorizationException('Anda tidak berizin melihat NIK.');
        }

        $nik = $user->nik;

        if ($nik === null) {
            throw PelanggaranAturanPengguna::nikKosong();
        }

        DB::transaction(fn () => $this->log(AksiPengguna::NikDitampilkan, $pelaku, $user));

        return $nik;
    }

    /**
     * Validate the account part of the staff form (PtkService), without
     * writing: name, username, email and every biodata rule, with
     * jenis_kelamin required. $user is the account being changed or linked,
     * null for a new account. Must run inside a transaction (it locks the
     * user rows). simpanAkunPtk() validates again before writing.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function validasiAkunPtk(array $data, ?User $user): void
    {
        $this->validateAccountData($data, $user, akunTanpaPanel: true);
    }

    /**
     * The same check as validasiAkunPtk() for the student form
     * (SiswaService), whose username is the NIS.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function validasiAkunSiswa(array $data, ?User $user): void
    {
        $this->validateAccountData($data, $user, akunTanpaPanel: true);
    }

    /**
     * Create ($user null) or change the account of a staff member and give it
     * the staff role $peranPtk (guru or tendik), taking away the other staff
     * role. Panel roles are never touched. A new account is active, has no
     * panel role and gets a random password that is never shown. Must be
     * called inside the staff service's transaction.
     *
     * @param  array<string, mixed>  $data  name, username, email, nik, hapus_nik and biodata
     *
     * @throws ValidationException
     */
    public function simpanAkunPtk(User $pelaku, ?User $user, array $data, Peran $peranPtk): User
    {
        if (! in_array($peranPtk, Peran::peranStaf(), true)) {
            throw new LogicException("{$peranPtk->value} is not a staff role.");
        }

        return $this->simpanAkunTanpaPanel(
            $pelaku,
            $user,
            $data,
            $peranPtk,
            'peran_ptk',
            fn (User $lama) => $this->aturPeranPtk($pelaku, $lama, $peranPtk),
        );
    }

    /**
     * Create ($user null) or change the account of a student. The username
     * is the NIS, set by SiswaService. A new account is active, holds only the
     * siswa role (no admin panel access) and gets a random password that is
     * never shown. Roles of an existing account are not touched. Must be
     * called inside the student service's transaction.
     *
     * @param  array<string, mixed>  $data  name, username (the NIS), email, nik, hapus_nik and biodata
     *
     * @throws ValidationException
     */
    public function simpanAkunSiswa(User $pelaku, ?User $user, array $data): User
    {
        return $this->simpanAkunTanpaPanel(
            $pelaku,
            $user,
            $data,
            Peran::Siswa,
            'peran_siswa',
            fn (User $lama) => null,
        );
    }

    /**
     * Deactivate the account of a staff member or student who left
     * (PtkService, SiswaService), unless it holds a panel role or is already
     * inactive. Returns the id of the log entry of the deactivation, or null
     * when the account was left alone.
     */
    public function nonaktifkanKarenaStatusKeluar(User $pelaku, User $user): ?int
    {
        $this->ensureCanManage($pelaku, $user);

        return DB::transaction(function () use ($pelaku, $user): ?int {
            $this->lockUser($user);

            if ($user->peranPanel() !== null || ! $user->status_aktif) {
                return null;
            }

            $entry = $this->changeStatus($pelaku, $user, false);

            return $entry === null ? null : (int) $entry->getKey();
        });
    }

    /**
     * Reactivate the account of a staff member or student who comes back, but
     * only when it is still inactive because of the deactivation logged as
     * $logId: the latest status change of the account must be that entry. An
     * account activated or deactivated in Kelola Pengguna afterwards is left
     * alone.
     */
    public function aktifkanKembaliSetelahStatusKeluar(User $pelaku, User $user, int $logId): void
    {
        $this->ensureCanManage($pelaku, $user);

        DB::transaction(function () use ($pelaku, $user, $logId): void {
            $this->lockUser($user);

            if ($user->status_aktif) {
                return;
            }

            $perubahanTerakhir = Activity::query()
                ->where('log_name', self::LOG_NAME)
                ->where('subject_type', $user->getMorphClass())
                ->where('subject_id', $user->getKey())
                ->whereIn('event', [AksiPengguna::Diaktifkan->value, AksiPengguna::Dinonaktifkan->value])
                ->latest('id')
                ->value('id');

            if ((int) $perubahanTerakhir === $logId) {
                $this->changeStatus($pelaku, $user, true);
            }
        });
    }

    /**
     * Normalize a phone number to the 62 format: spaces, dashes, dots and
     * brackets are dropped, then a leading 0 or +62 becomes 62. Anything else
     * is returned as is and fails validation.
     */
    public static function normalisasiNoHp(string $noHp): string
    {
        $noHp = (string) preg_replace('/[\s\-.()]+/u', '', $noHp);

        return match (true) {
            str_starts_with($noHp, '+62') => substr($noHp, 1),
            str_starts_with($noHp, '0') => '62'.substr($noHp, 1),
            default => $noHp,
        };
    }

    /**
     * Create an active super_admin from the terminal (simas:buat-super-admin).
     * There is no acting user: the log entry has no causer and shows as
     * "Sistem". Allowed at any time, also when other super admins exist.
     *
     * @param  array{name: string, username: string, password: string, password_confirmation: string}  $data
     *
     * @throws ValidationException
     */
    public function createSuperAdminFromSystem(array $data): User
    {
        $data['name'] = trim($data['name']);
        $data['username'] = User::normalizeUsername($data['username']);

        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): User => DB::transaction(function () use ($data): User {
            $this->lockUsers();

            /** @var array{name: string, username: string, password: string} $validated */
            $validated = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'username' => self::usernameRules(),
                'password' => [...self::passwordRules(), 'confirmed'],
            ], [
                'username.unique' => 'Username sudah dipakai.',
                'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
            ], [
                'name' => 'nama',
                'password' => 'kata sandi',
            ])->validate();

            $user = User::create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => null,
                'password' => $validated['password'],
                'status_aktif' => true,
            ]);

            $user->syncRoles([Peran::SuperAdmin->value]);

            $this->log(AksiPengguna::Dibuat, null, $user, [
                'attributes' => [...$this->createdAttributes($user), 'peran' => Peran::SuperAdmin->value],
            ]);

            return $user;
        }, self::ATTEMPTS));
    }

    /**
     * Username rules shared by every entry point (panel form, terminal).
     *
     * @return list<string|Unique>
     */
    public static function usernameRules(?User $ignore = null): array
    {
        return ['required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($ignore)];
    }

    /**
     * Email rules shared by the panel form and this service. Email is optional.
     *
     * @return list<string|Unique>
     */
    public static function emailRules(?User $ignore = null): array
    {
        return ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignore)];
    }

    /**
     * Password rules shared by every entry point (panel forms, terminal).
     *
     * @return list<string|Password>
     */
    public static function passwordRules(): array
    {
        return ['required', 'string', Password::defaults()];
    }

    /**
     * Whether $pelaku may change $user at all. Also used by UserPolicy so the
     * UI hides what the service would reject.
     */
    public function canManage(User $pelaku, User $user): bool
    {
        if ($pelaku->isSuperAdmin()) {
            return true;
        }

        if ($user->isSuperAdmin()) {
            return false;
        }

        return ! ($user->hasRole(Peran::Operator->value) && ! $pelaku->is($user));
    }

    /**
     * Panel roles are granted (and taken away) only by a super_admin. The
     * staff roles guru and tendik may be granted by anyone allowed to manage
     * the user; that check belongs to the caller.
     */
    public function canGrantRole(User $pelaku, Peran $peran): bool
    {
        return ! $peran->aksesPanel() || $pelaku->isSuperAdmin();
    }

    /**
     * @return array<string, string>
     */
    public function grantableRoleOptions(User $pelaku): array
    {
        return array_filter(
            Peran::opsiPanel(),
            fn (string $value): bool => $this->canGrantRole($pelaku, Peran::from($value)),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Validate the account fields of create() ($ignore null, password
     * required) and update() (password not accepted here). The name is
     * trimmed and the username normalized first, like every other entry
     * point. The panel form checks the same rules for convenience; this is
     * the check that counts. Must run inside a transaction (it locks the
     * user rows before the unique checks).
     *
     * Biodata (see the class docblock) is validated in the same pass, so the
     * form shows every error at once. Only the biodata keys present in $data
     * are returned; nik is returned only when filled.
     *
     * @param  array<string, mixed>  $data
     * @return array{name: string, username: string, email: string|null, peran: string|null, password?: string, nik?: string, hapus_nik?: bool, jenis_kelamin?: string|null, tempat_lahir?: string|null, tanggal_lahir?: string|null, agama?: string|null, alamat?: string|null, no_hp?: string|null, foto?: string|null}
     *
     * @throws ValidationException
     */
    private function validateAccountData(array $data, ?User $ignore, bool $akunTanpaPanel = false): array
    {
        $this->lockUsers();

        if (is_string($data['name'] ?? null)) {
            $data['name'] = trim($data['name']);
        }

        if (is_string($data['username'] ?? null)) {
            $data['username'] = User::normalizeUsername($data['username']);
        }

        $biodata = $this->prepareBiodata($data, [...self::BIODATA_FIELDS, 'nik']);

        if ($ignore !== null && array_key_exists('hapus_nik', $data)) {
            $biodata['hapus_nik'] = $data['hapus_nik'];
        }

        if ($akunTanpaPanel) {
            $biodata['jenis_kelamin'] ??= null;
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'username' => self::usernameRules($ignore),
            'email' => self::emailRules($ignore),
            ...Arr::only($this->biodataRules($ignore), array_keys($biodata)),
        ];

        if ($akunTanpaPanel) {
            $rules['jenis_kelamin'] = ['required', Rule::enum(JenisKelamin::class)];
        } else {
            $rules['peran'] = ['present', 'nullable', 'string', Rule::in(Peran::nilaiPanel())];
        }

        if ($ignore === null && ! $akunTanpaPanel) {
            $rules['password'] = self::passwordRules();
        }

        $validator = Validator::make([...Arr::except($data, ['nik', 'hapus_nik', ...self::BIODATA_FIELDS]), ...$biodata], $rules, [
            'username.unique' => 'Username sudah dipakai.',
            'email.unique' => 'Email sudah dipakai.',
            ...self::BIODATA_MESSAGES,
        ], [
            'name' => 'nama',
            'peran' => 'role',
            'password' => 'kata sandi',
            ...self::BIODATA_ATTRIBUTES,
        ]);

        $validator->after(function (ValidatorInstance $validator): void {
            $data = $validator->getData();

            if (filter_var($data['hapus_nik'] ?? false, FILTER_VALIDATE_BOOLEAN) && filled($data['nik'] ?? null)) {
                $validator->errors()->add('nik', 'Pilih salah satu: isi NIK baru, atau hapus NIK.');
            }
        });

        /** @var array{name: string, username: string, email?: string|null, peran: string|null, password?: string, nik?: string|null, hapus_nik?: bool|string|int, jenis_kelamin?: string|null, tempat_lahir?: string|null, tanggal_lahir?: string|null, agama?: string|null, alamat?: string|null, no_hp?: string|null, foto?: string|null} $validated */
        $validated = $validator->validate();

        if (array_key_exists('hapus_nik', $validated)) {
            $validated['hapus_nik'] = filter_var($validated['hapus_nik'], FILTER_VALIDATE_BOOLEAN);
        }

        if (($validated['nik'] ?? null) === null) {
            unset($validated['nik']);
        }

        return [...$validated, 'email' => filled($validated['email'] ?? null) ? $validated['email'] : null];
    }

    /**
     * Rules for every biodata key. None of the messages contains the value
     * that was typed, so a NIK never ends up in an error message.
     *
     * @return array<string, list<mixed>>
     */
    private function biodataRules(?User $ignore): array
    {
        $currentFoto = $ignore?->foto;

        return [
            'nik' => ['bail', 'nullable', 'string', 'regex:/^\d{16}$/', function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
                try {
                    $taken = User::query()
                        ->denganNik((string) $value)
                        ->when($ignore, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
                        ->exists();
                } catch (KunciNikTidakSiap) {
                    $fail(self::PESAN_KUNCI_NIK);

                    return;
                }

                if ($taken) {
                    $fail('NIK ini sudah terdaftar pada pengguna lain.');
                }
            }],
            'hapus_nik' => ['boolean'],
            'jenis_kelamin' => ['nullable', Rule::enum(JenisKelamin::class)],
            'tempat_lahir' => ['nullable', 'string', 'max:100'],
            'tanggal_lahir' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'agama' => ['nullable', Rule::enum(Agama::class)],
            'alamat' => ['nullable', 'string', 'max:1000'],
            'no_hp' => ['nullable', 'string', 'regex:/^62[1-9]\d{7,12}$/'],
            'foto' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($currentFoto, $ignore): void {
                if ($value === $currentFoto) {
                    return;
                }

                if (! $this->isStoredPhoto((string) $value) || $this->isPhotoOfAnotherUser((string) $value, $ignore)) {
                    $fail(self::PESAN_FOTO);
                }
            }],
        ];
    }

    /**
     * Trim the $fields present in $data, turn empty strings into null and
     * normalize no_hp. Keys that are not sent stay absent.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function prepareBiodata(array $data, array $fields): array
    {
        $prepared = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = is_string($data[$field]) ? trim($data[$field]) : $data[$field];

            if ($field === 'no_hp' && is_string($value) && $value !== '') {
                $value = self::normalisasiNoHp($value);
            }

            $prepared[$field] = $value === '' ? null : $value;
        }

        return $prepared;
    }

    /**
     * @param  array{nik?: string, hapus_nik?: bool}  $validated
     */
    private function applyNik(User $user, array $validated): void
    {
        if ($validated['hapus_nik'] ?? false) {
            $user->isiNik(null);

            return;
        }

        $nik = $validated['nik'] ?? null;

        if ($nik !== null && $nik !== $user->nik) {
            $user->isiNik($nik);
        }
    }

    /**
     * LOGGED_ATTRIBUTES as plain values (enums as their value, dates as Y-m-d).
     *
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        $values = [];

        foreach (self::LOGGED_ATTRIBUTES as $attribute) {
            $value = $user->getAttribute($attribute);

            $values[$attribute] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof CarbonInterface => $value->toDateString(),
                default => $value,
            };
        }

        return $values;
    }

    /**
     * The account fields, the biodata that was filled, and the nik/foto markers.
     *
     * @return array<string, mixed>
     */
    private function createdAttributes(User $user): array
    {
        $attributes = array_filter(
            $this->snapshot($user),
            fn (mixed $value, string $key): bool => $value !== null || ! in_array($key, self::LOGGED_BIODATA, true),
            ARRAY_FILTER_USE_BOTH,
        );

        return [...$attributes, ...$this->markers(null, $user->nik_hash, null, $user->foto)];
    }

    /**
     * Marker words for nik and foto, which are never logged as values.
     *
     * @return array<string, string>
     */
    private function markers(?string $nikHashBefore, ?string $nikHashAfter, ?string $fotoBefore, ?string $fotoAfter): array
    {
        return array_filter([
            'nik' => self::marker($nikHashBefore, $nikHashAfter, 'diisi', 'diubah', 'dihapus'),
            'foto' => self::marker($fotoBefore, $fotoAfter, 'diunggah', 'diganti', 'dihapus'),
        ]);
    }

    private static function marker(?string $before, ?string $after, string $added, string $changed, string $removed): ?string
    {
        return match (true) {
            $before === $after => null,
            $before === null => $added,
            $after === null => $removed,
            default => $changed,
        };
    }

    /**
     * Delete a replaced or removed photo once the transaction commits, so a
     * failed save never loses the current photo. Only files inside
     * FOTO_DIRECTORY are ever deleted.
     */
    private function deleteReplacedPhotoAfterCommit(?string $before, ?string $after): void
    {
        if ($before !== null && $before !== $after && $this->isInPhotoDirectory($before)) {
            DB::afterCommit(fn () => Storage::disk(self::FOTO_DISK)->delete($before));
        }
    }

    /**
     * A photo path from the form is trusted only if it points into this
     * service's own folder and the file there is a JPG, PNG or WebP of at
     * most FOTO_MAKS_KB. Livewire state can be tampered with, so the form's
     * FileUpload is not proof of an upload.
     *
     * The type is read from the file's content: the disk's mimeType() falls
     * back to the extension when the content is not recognized, so a text
     * file named .png would pass it.
     */
    private function isStoredPhoto(string $path): bool
    {
        $disk = Storage::disk(self::FOTO_DISK);

        if (! $this->isInPhotoDirectory($path) || ! $disk->exists($path) || $disk->size($path) > self::FOTO_MAKS_KB * 1024) {
            return false;
        }

        return in_array((new finfo(FILEINFO_MIME_TYPE))->buffer((string) $disk->get($path)), self::FOTO_MIME, true);
    }

    /**
     * Another account already points at this file. Accepting it would let a
     * tampered form take over someone else's photo, and the next replacement
     * on either account would delete it from under the other.
     */
    private function isPhotoOfAnotherUser(string $path, ?User $ignore): bool
    {
        return User::query()
            ->where('foto', $path)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
            ->exists();
    }

    /**
     * The path of the user's photo when it may be served: inside
     * FOTO_DIRECTORY and present on FOTO_DISK. Used by the pengguna.foto
     * route, which never serves anything else.
     */
    public function pathFotoUntukDisajikan(User $user): ?string
    {
        $foto = $user->foto;

        if ($foto === null || ! $this->isInPhotoDirectory($foto) || ! Storage::disk(self::FOTO_DISK)->exists($foto)) {
            return null;
        }

        return $foto;
    }

    private function isInPhotoDirectory(string $path): bool
    {
        return str_starts_with($path, self::FOTO_DIRECTORY.'/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');
    }

    /**
     * Shared by simpanAkunPtk() and simpanAkunSiswa(): an account without a
     * panel role. A new account gets the role $peran, logged in the "dibuat"
     * entry under $kunciLogPeran; $aturPeran adjusts the roles of an existing
     * account.
     *
     * @param  array<string, mixed>  $data
     * @param  Closure(User): mixed  $aturPeran
     *
     * @throws ValidationException
     */
    private function simpanAkunTanpaPanel(User $pelaku, ?User $user, array $data, Peran $peran, string $kunciLogPeran, Closure $aturPeran): User
    {
        if ($user !== null) {
            $this->ensureCanManage($pelaku, $user);
        }

        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): User => DB::transaction(function () use ($pelaku, $user, $data, $peran, $kunciLogPeran, $aturPeran): User {
            $data = $this->validateAccountData($data, $user, akunTanpaPanel: true);
            $this->ensureCanGrantRole($pelaku, $peran);

            if ($user === null) {
                $user = new User([
                    'name' => $data['name'],
                    'username' => $data['username'],
                    'email' => $data['email'] ?? null,
                    'password' => Str::password(32),
                    'status_aktif' => true,
                    ...Arr::only($data, self::BIODATA_FIELDS),
                ]);
                $this->applyNik($user, $data);
                $user->save();
                $user->assignRole($peran->value);

                $this->log(AksiPengguna::Dibuat, $pelaku, $user, [
                    'attributes' => [...$this->createdAttributes($user), 'peran' => null, $kunciLogPeran => $peran->value],
                ]);

                return $user;
            }

            $before = $this->snapshot($user);
            $nikHashBefore = $user->nik_hash;
            $fotoBefore = $user->foto;

            $user->fill([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                ...Arr::only($data, self::BIODATA_FIELDS),
            ]);
            $this->applyNik($user, $data);
            $user->save();

            $changes = $this->diff($before, $this->snapshot($user), $this->markers($nikHashBefore, $user->nik_hash, $fotoBefore, $user->foto));
            $this->deleteReplacedPhotoAfterCommit($fotoBefore, $user->foto);

            if ($changes !== null) {
                $this->log(AksiPengguna::Diubah, $pelaku, $user, $changes);
            }

            $aturPeran($user);

            return $user;
        }, self::ATTEMPTS));
    }

    /**
     * Leave $baru as the only panel role (none when null), also when the
     * user held both panel roles. Staff roles (guru, tendik) stay untouched.
     */
    private function aturPeranPanel(User $user, ?Peran $baru): void
    {
        foreach (Peran::peranPanel() as $peran) {
            if ($peran !== $baru && $user->hasRole($peran->value)) {
                $user->removeRole($peran->value);
            }
        }

        if ($baru !== null && ! $user->hasRole($baru->value)) {
            $user->assignRole($baru->value);
        }
    }

    /**
     * Give the staff role $baru (guru or tendik) and take away the other
     * staff role. Only the staff roles are touched: panel roles and any other
     * role stay. A change is logged as role_berubah with peran_ptk.
     */
    private function aturPeranPtk(User $pelaku, User $user, Peran $baru): void
    {
        $user->unsetRelation('roles');
        $lama = null;

        foreach (Peran::peranStaf() as $peran) {
            if (! $user->hasRole($peran->value)) {
                continue;
            }

            if ($peran === $baru) {
                $lama = $peran;

                continue;
            }

            $lama ??= $peran;
            $user->removeRole($peran->value);
        }

        if ($lama === $baru) {
            return;
        }

        $user->assignRole($baru->value);

        $this->log(AksiPengguna::RoleBerubah, $pelaku, $user, [
            'attributes' => ['peran_ptk' => $baru->value],
            'old' => ['peran_ptk' => $lama?->value],
        ]);
    }

    /**
     * Lock the user's row and read it again, roles included.
     */
    private function lockUser(User $user): void
    {
        User::query()->whereKey($user->getKey())->lockForUpdate()->first(['id']);
        $user->refresh();
        $user->unsetRelation('roles');
    }

    /**
     * Must run inside a transaction, before a username/email unique check,
     * so two concurrent saves are serialized instead of both passing it.
     */
    private function lockUsers(): void
    {
        User::query()->lockForUpdate()->get(['id']);
    }

    /**
     * Returns the log entry of the change, or null when nothing changed.
     */
    private function changeStatus(User $pelaku, User $user, bool $statusAktif): ?Activity
    {
        if ($user->status_aktif === $statusAktif) {
            return null;
        }

        $user->update(['status_aktif' => $statusAktif]);

        return $this->log($statusAktif ? AksiPengguna::Diaktifkan : AksiPengguna::Dinonaktifkan, $pelaku, $user, [
            'attributes' => ['status_aktif' => $statusAktif],
            'old' => ['status_aktif' => ! $statusAktif],
        ]);
    }

    /**
     * Changed values with their old values, followed by the nik/foto markers
     * (which have no old value), or null when nothing changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, string>  $markers
     * @return array{attributes: array<string, mixed>, old: array<string, mixed>}|null
     */
    private function diff(array $before, array $after, array $markers = []): ?array
    {
        $changed = array_filter(
            $after,
            fn (mixed $value, string $key): bool => $value !== $before[$key],
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changed === [] && $markers === []) {
            return null;
        }

        return [
            'attributes' => [...$changed, ...$markers],
            'old' => array_intersect_key($before, $changed),
        ];
    }

    /**
     * A null $pelaku means the system (terminal) did it; shown as "Sistem".
     *
     * @param  array{attributes: array<string, mixed>, old?: array<string, mixed>}|null  $changes
     */
    private function log(AksiPengguna $aksi, ?User $pelaku, User $user, ?array $changes = null): ?Activity
    {
        $logger = activity(self::LOG_NAME);
        $logger = $pelaku === null ? $logger->causedByAnonymous() : $logger->causedBy($pelaku);
        $logger = $logger
            ->performedOn($user)
            ->event($aksi->value);

        if ($changes !== null) {
            $logger->withChanges($changes);
        }

        $entry = $logger->log($aksi->label());

        return $entry instanceof Activity ? $entry : null;
    }

    private function ensureCanManage(User $pelaku, User $user): void
    {
        if (! $this->canManage($pelaku, $user)) {
            throw PelanggaranAturanPengguna::tidakBolehMengelola();
        }
    }

    private function ensureCanGrantRole(User $pelaku, Peran $peran): void
    {
        if (! $this->canGrantRole($pelaku, $peran)) {
            throw PelanggaranAturanPengguna::tidakBolehMemberiPeran($peran->value);
        }
    }

    /**
     * Must run inside a transaction: the other active super admins are locked
     * so two concurrent requests cannot both remove the last one.
     */
    private function ensureNotLastActiveSuperAdmin(User $user): void
    {
        if (! $user->status_aktif || ! $user->isSuperAdmin()) {
            return;
        }

        $otherActiveSuperAdminIds = User::query()
            ->role(Peran::SuperAdmin->value)
            ->where('status_aktif', true)
            ->whereKeyNot($user->getKey())
            ->lockForUpdate()
            ->pluck('id');

        if ($otherActiveSuperAdminIds->isEmpty()) {
            throw PelanggaranAturanPengguna::superAdminAktifTerakhir();
        }
    }
}
