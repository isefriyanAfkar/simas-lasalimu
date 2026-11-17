<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Domains\Foundation\Enums\Agama;
use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Filament\Resources\Users\Pages\EditUser;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

/**
 * The NIK field never holds the stored NIK: on edit it starts empty with the
 * masked NIK as placeholder, and leaving it empty keeps the stored one. The
 * full NIK appears only after the "Tampilkan NIK" action on EditUser, which
 * is logged.
 */
class UserForm
{
    public const string KETERANGAN_NIK = 'NIK hanya digunakan untuk keperluan administrasi sekolah. Data ini disimpan dalam bentuk terenkripsi, ditampilkan tersamar, dan setiap kali dibuka tercatat dalam log aktivitas.';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Akun')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('username')
                            ->label('Nama pengguna')
                            ->required()
                            ->mutateStateForValidationUsing(fn (?string $state): ?string => filled($state) ? User::normalizeUsername($state) : $state)
                            ->rules(fn (?User $record): array => UserService::usernameRules($record))
                            ->validationMessages(['unique' => 'Username sudah dipakai.']),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255)
                            ->rules(fn (?User $record): array => UserService::emailRules($record))
                            ->validationMessages(['unique' => 'Email sudah dipakai.']),
                        Select::make('peran')
                            ->label('Role')
                            ->options(fn (?User $record): array => self::roleOptions($record))
                            ->placeholder('Tanpa akses panel')
                            ->helperText('Tanpa role, pengguna tidak bisa masuk panel admin. Role guru dan tendik diatur lewat layar Guru dan Staf.'),
                        TextInput::make('password')
                            ->label('Kata sandi')
                            ->password()
                            ->revealable()
                            ->required()
                            ->rules(UserService::passwordRules())
                            ->visibleOn('create'),
                    ]),
                Section::make('Biodata')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nik')
                            ->label('NIK')
                            ->maxLength(16)
                            ->autocomplete(false)
                            ->placeholder(fn (?User $record): ?string => $record?->nikTersamar())
                            ->hint(fn (Component $livewire): ?string => $livewire instanceof EditUser && $livewire->nikTerbuka !== null ? "NIK: {$livewire->nikTerbuka}" : null)
                            ->helperText(fn (?User $record): string => $record?->nik_hash !== null
                                ? 'Kosongkan untuk tetap memakai NIK tersimpan. '.self::KETERANGAN_NIK
                                : self::KETERANGAN_NIK)
                            ->disabled(fn (Get $get): bool => (bool) $get('hapus_nik')),
                        Toggle::make('hapus_nik')
                            ->label('Hapus NIK')
                            ->live()
                            ->visible(fn (?User $record): bool => $record?->nik_hash !== null),
                        Select::make('jenis_kelamin')
                            ->label('Jenis kelamin')
                            ->options(JenisKelamin::options()),
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
                        self::fotoField(),
                    ]),
            ]);
    }

    /**
     * Shared with the profile page. UserService checks the stored file again.
     * Files go to the private disk; the preview of a stored photo uses the
     * protected pengguna.foto route instead of a signed temporary URL.
     */
    public static function fotoField(): FileUpload
    {
        return FileUpload::make('foto')
            ->label('Foto')
            ->image()
            ->avatar()
            ->disk(UserService::FOTO_DISK)
            ->directory(UserService::FOTO_DIRECTORY)
            ->visibility('private')
            ->acceptedFileTypes(UserService::FOTO_MIME)
            ->maxSize(UserService::FOTO_MAKS_KB)
            ->getUploadedFileUsing(fn (string $file, ?User $record): ?array => self::storedPhoto($file, $record))
            ->helperText('JPG, PNG, atau WebP, paling besar 1 MB.');
    }

    /**
     * @return array{name: string, size: int, type: ?string, url: ?string}|null
     */
    private static function storedPhoto(string $file, ?User $record): ?array
    {
        if ($record === null || $record->foto !== $file) {
            return null;
        }

        $disk = Storage::disk(UserService::FOTO_DISK);

        return [
            'name' => basename($file),
            'size' => (int) $disk->size($file),
            'type' => $disk->mimeType($file) ?: null,
            'url' => $record->urlFoto(),
        ];
    }

    /**
     * Panel roles the signed-in user may grant, plus the panel role the record
     * already has. Guru and tendik are never offered here.
     *
     * @return array<string, string>
     */
    private static function roleOptions(?User $record): array
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        $options = app(UserService::class)->grantableRoleOptions($pelaku);

        $peranSaatIni = $record?->peranPanel();

        if ($peranSaatIni !== null) {
            $options[$peranSaatIni->value] = $peranSaatIni->label();
        }

        return $options;
    }
}
