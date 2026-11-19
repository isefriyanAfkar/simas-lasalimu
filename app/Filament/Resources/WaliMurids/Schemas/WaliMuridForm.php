<?php

namespace App\Filament\Resources\WaliMurids\Schemas;

use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\WaliMurid;
use App\Filament\Resources\Users\Schemas\UserForm;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The parent's biodata and job. Field rules here are only for convenience;
 * WaliMuridService (and UserService for the account) decides. There is no
 * username field (wali00123, set by the service) and no account status (the
 * account stays inactive until the parent portal exists).
 *
 * The NIK field never holds the stored NIK; the masked NIK is shown only to
 * whoever may reveal it (UserPolicy::viewNik).
 */
class WaliMuridForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Wali murid')
                    ->columns(2)
                    ->schema(self::fields()),
            ]);
    }

    /**
     * The fields of an existing parent. The "Wali baru" form on the student's
     * page has its own fields (WaliRelationManager): those depend on the
     * form state, not on a stored parent.
     *
     * @return list<mixed>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(255),
            Select::make('jenis_kelamin')
                ->label('Jenis kelamin')
                ->options(JenisKelamin::options())
                ->helperText('Ayah selalu laki-laki dan ibu selalu perempuan; boleh dikosongkan.'),
            TextInput::make('no_hp')
                ->label('Nomor HP')
                ->helperText('Boleh ditulis 08…, 62…, atau +62…. Ayah dan ibu boleh memakai nomor yang sama.')
                ->maxLength(20),
            TextInput::make('pekerjaan')
                ->label('Pekerjaan')
                ->maxLength(100),
            TextInput::make('nik')
                ->label('NIK')
                ->maxLength(16)
                ->autocomplete(false)
                ->placeholder(fn (?WaliMurid $record): ?string => self::nikAccount($record)?->nikTersamar())
                ->helperText(fn (?WaliMurid $record): string => self::nikAccount($record)?->nik_hash !== null
                    ? 'Kosongkan untuk tetap memakai NIK tersimpan. '.UserForm::KETERANGAN_NIK
                    : UserForm::KETERANGAN_NIK)
                ->disabled(fn (Get $get): bool => (bool) $get('hapus_nik')),
            Toggle::make('hapus_nik')
                ->label('Hapus NIK')
                ->live()
                ->visible(fn (?WaliMurid $record): bool => self::nikAccount($record)?->nik_hash !== null),
            Textarea::make('alamat')
                ->label('Alamat')
                ->maxLength(1000)
                ->columnSpanFull(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillFromRecord(WaliMurid $wali, array $data): array
    {
        $user = $wali->user;

        return [
            ...$data,
            'name' => $user->name,
            'jenis_kelamin' => $user->jenis_kelamin?->value,
            'no_hp' => $user->no_hp,
            'alamat' => $user->alamat,
            'nik' => null,
            'hapus_nik' => false,
        ];
    }

    private static function nikAccount(?WaliMurid $record): ?User
    {
        $user = $record?->user;

        return $user !== null && Filament::auth()->user()?->can('viewNik', $user) ? $user : null;
    }
}
