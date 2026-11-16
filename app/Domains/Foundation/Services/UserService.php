<?php

namespace App\Domains\Foundation\Services;

use App\Domains\Foundation\Enums\AksiPengguna;
use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

/**
 * The only place that writes user accounts. Every method takes the acting
 * user ($pelaku) so the user management rules can be enforced here.
 *
 * Rules:
 * - a user cannot deactivate their own account
 * - the last active super_admin cannot be deactivated or lose the super_admin role
 * - a non super_admin cannot change, (de)activate or reset the password of a
 *   super_admin or of another operator
 * - a non super_admin cannot grant the super_admin or operator role
 *
 * create() and update() validate their input here (see validateAccountData),
 * whatever the caller already checked. Every save that checks username or
 * email uniqueness does so inside a transaction that first locks the user
 * rows, and KeunikanDatabase turns a unique index violation that still
 * happens into a field error.
 *
 * Every successful write is recorded in the activity log (log "pengguna") in
 * the same transaction. Only the attributes in LOGGED_ATTRIBUTES (and the role
 * name) are ever written to the log, so passwords and remember tokens never
 * reach it.
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
    ];

    /**
     * @var list<string>
     */
    private const array LOGGED_ATTRIBUTES = ['name', 'username', 'email', 'status_aktif'];

    /**
     * Input is validated before the role check, because the role must be a
     * known Peran first. So invalid input (for example a taken username) is
     * reported before a role the actor may not grant. This reveals nothing:
     * the actor sees those usernames anyway. update() checks whether the actor
     * may manage the user first, since there the target already exists.
     *
     * @param  array{name: string, username: string, email?: string|null, password: string, peran: string}  $data
     *
     * @throws ValidationException
     */
    public function create(User $pelaku, array $data): User
    {
        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): User => DB::transaction(function () use ($pelaku, $data): User {
            $data = $this->validateAccountData($data, null);
            $peran = Peran::from($data['peran']);

            $this->ensureCanGrantRole($pelaku, $peran);

            $user = User::create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
                'status_aktif' => true,
            ]);

            $user->syncRoles([$peran->value]);

            $this->log(AksiPengguna::Dibuat, $pelaku, $user, [
                'attributes' => [...$user->only(self::LOGGED_ATTRIBUTES), 'peran' => $peran->value],
            ]);

            return $user;
        }, self::ATTEMPTS));
    }

    /**
     * @param  array{name: string, username: string, email?: string|null, peran: string}  $data
     *
     * @throws ValidationException
     */
    public function update(User $pelaku, User $user, array $data): User
    {
        $this->ensureCanManage($pelaku, $user);

        return KeunikanDatabase::jaga(self::INDEKS_UNIK, fn (): User => DB::transaction(function () use ($pelaku, $user, $data): User {
            $data = $this->validateAccountData($data, $user);
            $peran = Peran::from($data['peran']);

            if (! $user->hasRole($peran->value)) {
                $this->ensureCanGrantRole($pelaku, $peran);
            }

            if ($peran !== Peran::SuperAdmin) {
                $this->ensureNotLastActiveSuperAdmin($user);
            }

            $before = $user->only(self::LOGGED_ATTRIBUTES);
            $roleBefore = $user->getRoleNames()->first();

            $user->update([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
            ]);

            $user->syncRoles([$peran->value]);

            $changes = $this->diff($before, $user->only(self::LOGGED_ATTRIBUTES));

            if ($changes !== null) {
                $this->log(AksiPengguna::Diubah, $pelaku, $user, $changes);
            }

            if ($roleBefore !== $peran->value) {
                $this->log(AksiPengguna::RoleBerubah, $pelaku, $user, [
                    'attributes' => ['peran' => $peran->value],
                    'old' => ['peran' => $roleBefore],
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
     * A signed-in user changing their own name and, optionally, password.
     * Every other key (such as username) is ignored.
     *
     * @param  array{name: string, password?: string|null}  $data  password may be plaintext or an already-hashed value (see class docblock)
     */
    public function updateOwnProfile(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $before = $user->only(['name']);

            $user->update(['name' => $data['name']]);

            $changes = $this->diff($before, $user->only(['name']));

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
                'attributes' => [...$user->only(self::LOGGED_ATTRIBUTES), 'peran' => Peran::SuperAdmin->value],
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

    public function canGrantRole(User $pelaku, Peran $peran): bool
    {
        return $pelaku->isSuperAdmin();
    }

    /**
     * @return array<string, string>
     */
    public function grantableRoleOptions(User $pelaku): array
    {
        return array_filter(
            Peran::options(),
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
     * @param  array<string, mixed>  $data
     * @return array{name: string, username: string, email: string|null, peran: string, password?: string}
     *
     * @throws ValidationException
     */
    private function validateAccountData(array $data, ?User $ignore): array
    {
        $this->lockUsers();

        if (is_string($data['name'] ?? null)) {
            $data['name'] = trim($data['name']);
        }

        if (is_string($data['username'] ?? null)) {
            $data['username'] = User::normalizeUsername($data['username']);
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'username' => self::usernameRules($ignore),
            'email' => self::emailRules($ignore),
            'peran' => ['required', Rule::enum(Peran::class)],
        ];

        if ($ignore === null) {
            $rules['password'] = self::passwordRules();
        }

        /** @var array{name: string, username: string, email?: string|null, peran: string, password?: string} $validated */
        $validated = Validator::make($data, $rules, [
            'username.unique' => 'Username sudah dipakai.',
            'email.unique' => 'Email sudah dipakai.',
        ], [
            'name' => 'nama',
            'peran' => 'role',
            'password' => 'kata sandi',
        ])->validate();

        return [...$validated, 'email' => filled($validated['email'] ?? null) ? $validated['email'] : null];
    }

    /**
     * Must run inside a transaction, before a username/email unique check,
     * so two concurrent saves are serialized instead of both passing it.
     */
    private function lockUsers(): void
    {
        User::query()->lockForUpdate()->get(['id']);
    }

    private function changeStatus(User $pelaku, User $user, bool $statusAktif): void
    {
        if ($user->status_aktif === $statusAktif) {
            return;
        }

        $user->update(['status_aktif' => $statusAktif]);

        $this->log($statusAktif ? AksiPengguna::Diaktifkan : AksiPengguna::Dinonaktifkan, $pelaku, $user, [
            'attributes' => ['status_aktif' => $statusAktif],
            'old' => ['status_aktif' => ! $statusAktif],
        ]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{attributes: array<string, mixed>, old: array<string, mixed>}|null
     */
    private function diff(array $before, array $after): ?array
    {
        $changed = array_filter(
            $after,
            fn (mixed $value, string $key): bool => $value !== $before[$key],
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changed === []) {
            return null;
        }

        return [
            'attributes' => $changed,
            'old' => array_intersect_key($before, $changed),
        ];
    }

    /**
     * A null $pelaku means the system (terminal) did it; shown as "Sistem".
     *
     * @param  array{attributes: array<string, mixed>, old?: array<string, mixed>}|null  $changes
     */
    private function log(AksiPengguna $aksi, ?User $pelaku, User $user, ?array $changes = null): void
    {
        $logger = activity(self::LOG_NAME);
        $logger = $pelaku === null ? $logger->causedByAnonymous() : $logger->causedBy($pelaku);
        $logger = $logger
            ->performedOn($user)
            ->event($aksi->value);

        if ($changes !== null) {
            $logger->withChanges($changes);
        }

        $logger->log($aksi->label());
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
