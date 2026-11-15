<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(255),
                TextInput::make('username')
                    ->label('Nama pengguna')
                    ->required()
                    ->rules(fn (?User $record): array => UserService::usernameRules($record))
                    ->validationMessages(['unique' => 'Username sudah dipakai.']),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('peran')
                    ->label('Role')
                    ->options(fn (?User $record): array => self::roleOptions($record))
                    ->required(),
                TextInput::make('password')
                    ->label('Kata sandi')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rules(UserService::passwordRules())
                    ->visibleOn('create'),
            ]);
    }

    /**
     * Roles the signed-in user may grant, plus the role the record already has.
     *
     * @return array<string, string>
     */
    private static function roleOptions(?User $record): array
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        $options = app(UserService::class)->grantableRoleOptions($pelaku);

        foreach (Peran::cases() as $peran) {
            if ($record?->hasRole($peran->value)) {
                $options[$peran->value] = $peran->label();
            }
        }

        return $options;
    }
}
