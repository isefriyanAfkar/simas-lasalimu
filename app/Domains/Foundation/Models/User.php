<?php

namespace App\Domains\Foundation\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domains\Foundation\Enums\Agama;
use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Policies\UserPolicy;
use App\Domains\Foundation\Services\KunciNikTidakSiap;
use App\Domains\Foundation\Services\PenjagaNik;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\WaliMurid;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

/**
 * Biodata is written only through UserService.
 *
 * nik is encrypted with APP_KEY (`encrypted` cast) and nik_hash is its HMAC
 * (PenjagaNik). Both are hidden from serialization and are not fillable: set
 * them together with isiNik(), and search with the denganNik() scope.
 *
 * @property string|null $nik
 * @property string|null $nik_hash
 * @property JenisKelamin|null $jenis_kelamin
 * @property string|null $tempat_lahir
 * @property Carbon|null $tanggal_lahir
 * @property Agama|null $agama
 * @property string|null $alamat
 * @property string|null $no_hp
 * @property string|null $foto
 */
#[Fillable(['name', 'username', 'email', 'password', 'status_aktif', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'agama', 'alamat', 'no_hp', 'foto'])]
#[Hidden(['password', 'remember_token', 'nik', 'nik_hash'])]
#[UseFactory(UserFactory::class)]
#[UsePolicy(UserPolicy::class)]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Normalize a username so storage and login compare the same form.
     */
    public static function normalizeUsername(string $username): string
    {
        return Str::lower(trim($username));
    }

    /**
     * Only active users holding a panel role may enter a Filament panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status_aktif && $this->hasAnyRole(Peran::nilaiPanel());
    }

    /**
     * The panel role (super_admin or operator), or null when the user has
     * none. Staff roles (guru, tendik) are ignored. Kelola Pengguna keeps at
     * most one; for older data holding both, super_admin is the one shown.
     */
    public function peranPanel(): ?Peran
    {
        return $this->semuaPeranPanel()[0] ?? null;
    }

    /**
     * Every panel role the user holds, super_admin first.
     *
     * @return list<Peran>
     */
    public function semuaPeranPanel(): array
    {
        $namaPeran = $this->getRoleNames()->all();

        return array_values(array_filter(
            Peran::peranPanel(),
            fn (Peran $peran): bool => in_array($peran->value, $namaPeran, true),
        ));
    }

    /**
     * Set the NIK and its hash together; null clears both.
     *
     * @throws KunciNikTidakSiap
     */
    public function isiNik(?string $nik): void
    {
        $this->setAttribute('nik', $nik);
        $this->setAttribute('nik_hash', $nik === null ? null : app(PenjagaNik::class)->hash($nik));
    }

    /**
     * "7404********0001", or null when there is no NIK.
     */
    public function nikTersamar(): ?string
    {
        return $this->nik === null ? null : PenjagaNik::samarkan($this->nik);
    }

    /**
     * The protected URL of the photo (route pengguna.foto), or null. The
     * "v" parameter changes with the file, so a replaced photo is not served
     * from the browser cache.
     */
    public function urlFoto(): ?string
    {
        return $this->foto === null ? null : route('pengguna.foto', ['user' => $this, 'v' => substr(md5($this->foto), 0, 8)]);
    }

    /**
     * The staff record (guru or tendik) of this account, if any.
     *
     * @return HasOne<Ptk, $this>
     */
    public function ptk(): HasOne
    {
        return $this->hasOne(Ptk::class);
    }

    /**
     * The student record of this account, if any. Its username is the NIS.
     *
     * @return HasOne<Siswa, $this>
     */
    public function siswa(): HasOne
    {
        return $this->hasOne(Siswa::class);
    }

    /**
     * The parent/guardian record of this account, if any.
     *
     * @return HasOne<WaliMurid, $this>
     */
    public function waliMurid(): HasOne
    {
        return $this->hasOne(WaliMurid::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Peran::SuperAdmin->value);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status_aktif' => 'boolean',
            'nik' => 'encrypted',
            'jenis_kelamin' => JenisKelamin::class,
            'tanggal_lahir' => 'date',
            'agama' => Agama::class,
        ];
    }

    /**
     * Store the username trimmed and lowercased.
     *
     * @return Attribute<string, string>
     */
    protected function username(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => static::normalizeUsername($value),
        );
    }

    /**
     * Users whose NIK is $nik, matched through nik_hash.
     *
     * @param  Builder<self>  $query
     *
     * @throws KunciNikTidakSiap
     */
    #[Scope]
    protected function denganNik(Builder $query, string $nik): void
    {
        $query->where('nik_hash', app(PenjagaNik::class)->hash($nik));
    }
}
