<?php

namespace App\Domains\Foundation\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Policies\UserPolicy;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'email', 'password', 'status_aktif'])]
#[Hidden(['password', 'remember_token'])]
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
}
