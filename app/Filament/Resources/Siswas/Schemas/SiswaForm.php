<?php

namespace App\Filament\Resources\Siswas\Schemas;

use App\Domains\Foundation\Enums\Agama;
use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\Siswa;
use App\Filament\Resources\Siswas\Pages\EditSiswa;
use App\Filament\Resources\Users\Schemas\UserForm;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Livewire\Component;

/**
 * One form for the account, the biodata and the student fields. Field rules
 * here are only for convenience; SiswaService (and UserService for the
 * account) decides. There is no username field: the username is the NIS.
 * There is no status field: the status changes only through the Ubah status
 * and Aktifkan kembali actions.
 *
 * The NIK field never holds the stored NIK: it starts empty with the masked
 * NIK as placeholder (only for whoever may reveal it), and leaving it empty
 * keeps the stored one.
 */
class SiswaForm
{
    public const string KETERANGAN_NIS = 'Hanya angka, 4 sampai 20 digit. NIS juga menjadi nama pengguna siswa untuk masuk.';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('nis')
                            ->label('NIS')
                            ->helperText(fn (string $operation): string => $operation === 'create'
                                ? self::KETERANGAN_NIS.' Akun baru mendapat kata sandi acak dan belum bisa dipakai masuk.'
                                : self::KETERANGAN_NIS)
                            ->required()
                            ->maxLength(20),
                        TextInput::make('nisn')
                            ->label('NISN')
                            ->helperText('10 angka. Boleh kosong.')
                            ->maxLength(10),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                    ]),
                Section::make('Biodata')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nik')
                            ->label('NIK')
                            ->maxLength(16)
                            ->autocomplete(false)
                            ->placeholder(fn (?Siswa $record): ?string => self::nikAccount($record)?->nikTersamar())
                            ->hint(fn (Component $livewire): ?string => $livewire instanceof EditSiswa && $livewire->nikTerbuka !== null ? "NIK: {$livewire->nikTerbuka}" : null)
                            ->helperText(fn (?Siswa $record): string => self::nikAccount($record)?->nik_hash !== null
                                ? 'Kosongkan untuk tetap memakai NIK tersimpan. '.UserForm::KETERANGAN_NIK
                                : UserForm::KETERANGAN_NIK)
                            ->disabled(fn (Get $get): bool => (bool) $get('hapus_nik')),
                        Toggle::make('hapus_nik')
                            ->label('Hapus NIK')
                            ->live()
                            ->visible(fn (?Siswa $record): bool => self::nikAccount($record)?->nik_hash !== null),
                        Select::make('jenis_kelamin')
                            ->label('Jenis kelamin')
                            ->options(JenisKelamin::options())
                            ->required(),
                        Select::make('agama')
                            ->label('Agama')
                            ->options(Agama::options()),
                        TextInput::make('tempat_lahir')
                            ->label('Tempat lahir')
                            ->maxLength(100),
                        DatePicker::make('tanggal_lahir')
                            ->label('Tanggal lahir')
                            ->maxDate(fn (): string => now()->subDay()->toDateString()),
                        TextInput::make('no_hp')
                            ->label('Nomor HP')
                            ->helperText('Boleh ditulis 08…, 62…, atau +62…. Disimpan dalam format 62.')
                            ->maxLength(20),
                        Textarea::make('alamat')
                            ->label('Alamat')
                            ->maxLength(1000),
                        UserForm::fotoField(fn (?Siswa $record): ?User => $record?->user),
                    ]),
                Section::make('Kesiswaan')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('tanggal_masuk')
                            ->label('Tanggal masuk')
                            ->maxDate(fn (): string => now()->toDateString()),
                        TextInput::make('asal_sekolah')
                            ->label('Asal sekolah')
                            ->maxLength(150),
                    ]),
            ]);
    }

    /**
     * The form state of a stored student: the student fields plus the
     * account and biodata, with the NIK left empty.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillFromRecord(Siswa $siswa, array $data): array
    {
        $user = $siswa->user;

        return [
            ...$data,
            'tanggal_masuk' => $siswa->tanggal_masuk?->toDateString(),
            'name' => $user->name,
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
        ];
    }

    /**
     * The account whose stored NIK the form may hint at (masked NIK as
     * placeholder, "keep the stored NIK" help text, Hapus NIK toggle): only
     * when the signed-in user may reveal that NIK (UserPolicy::viewNik).
     */
    private static function nikAccount(?Siswa $record): ?User
    {
        $user = $record?->user;

        return $user !== null && Filament::auth()->user()?->can('viewNik', $user) ? $user : null;
    }
}
