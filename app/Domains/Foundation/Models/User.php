<?php

namespace App\Domains\Foundation\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domains\Foundation\Enums\Agama;
use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Policies\UserPolicy;
use App\Domains\Foundation\Services\KunciNikTidakSiap;
use App\Domains\Foundation\Services\PenjagaNik;
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
        return $this->status_aktif && $this->hasAnyRole([Peran::SuperAdmin->value, Peran::Operator->value]);
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
