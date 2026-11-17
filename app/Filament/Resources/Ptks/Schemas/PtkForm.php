<?php

namespace App\Filament\Resources\Ptks\Schemas;

use App\Domains\Foundation\Enums\Agama;
use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Enums\Peran;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\JenisPtk;
use App\Domains\People\Models\Ptk;
use App\Domains\People\Services\StatusKepegawaianService;
use App\Filament\Resources\Ptks\Pages\EditPtk;
use App\Filament\Resources\Users\Schemas\UserForm;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * One form for the account, the biodata and the staff fields. Field rules
 * here are only for convenience; PtkService (and UserService for the
 * account) decides. There is no status field: the status changes only
 * through the Ubah status and Aktifkan kembali actions.
 *
 * The NIK field never holds the stored NIK: it starts empty with the masked
 * NIK as placeholder, and leaving it empty keeps the stored one.
 */
class PtkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Akun')
                    ->columns(2)
                    ->schema([
                        ToggleButtons::make('akun')
                            ->label('Akun')
                            ->options(['baru' => 'Buat akun baru', 'ada' => 'Pengguna yang sudah ada'])
                            ->default('baru')
                            ->inline()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('user_id', null);
                                self::clearAccount($set);
                            })
                            ->visibleOn('create')
                            ->columnSpanFull(),
                        Select::make('user_id')
                            ->label('Pengguna')
                            ->helperText('Hanya pengguna yang belum terdaftar sebagai guru/staf dan boleh Anda kelola.')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => self::accountsToLink($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => self::accountLabel(self::linkableAccount($value)))
                            ->live()
                            ->afterStateUpdated(fn (mixed $state, Set $set) => self::fillFromAccount($state, $set))
                            ->required(fn (Get $get): bool => $get('akun') === 'ada')
                            ->visible(fn (Get $get, string $operation): bool => $operation === 'create' && $get('akun') === 'ada')
                            ->columnSpanFull(),
                        TextInput::make('name')
                            ->label('Nama (tanpa gelar)')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('username')
                            ->label('Nama pengguna')
                            ->helperText(fn (string $operation): ?string => $operation === 'create' ? 'Akun baru mendapat kata sandi acak dan belum bisa dipakai masuk.' : null)
                            ->required()
                            ->maxLength(255),
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
                            ->placeholder(fn (?Ptk $record, Get $get): ?string => self::nikAccount($record, $get)?->nikTersamar())
                            ->hint(fn (Component $livewire): ?string => $livewire instanceof EditPtk && $livewire->nikTerbuka !== null ? "NIK: {$livewire->nikTerbuka}" : null)
                            ->helperText(fn (?Ptk $record, Get $get): string => self::nikAccount($record, $get)?->nik_hash !== null
                                ? 'Kosongkan untuk tetap memakai NIK tersimpan. '.UserForm::KETERANGAN_NIK
                                : UserForm::KETERANGAN_NIK)
                            ->disabled(fn (Get $get): bool => (bool) $get('hapus_nik')),
                        Toggle::make('hapus_nik')
                            ->label('Hapus NIK')
                            ->live()
                            ->visible(fn (?Ptk $record, Get $get): bool => $record !== null && self::nikAccount($record, $get)?->nik_hash !== null),
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
                        UserForm::fotoField(fn (?Ptk $record, Get $get): ?User => self::account($record, $get)),
                    ]),
                Section::make('Kepegawaian')
                    ->columns(2)
                    ->schema([
                        ToggleButtons::make('jenis_ptk')
                            ->label('Jenis')
                            ->options(JenisPtk::options())
                            ->inline()
                            ->required(),
                        Select::make('status_kepegawaian_id')
                            ->label('Status kepegawaian')
                            ->options(fn (?Ptk $record): array => app(StatusKepegawaianService::class)->opsiUntukDipilih($record?->status_kepegawaian_id))
                            ->helperText('Status yang sudah dinonaktifkan tidak bisa dipilih.')
                            ->required(),
                        TextInput::make('gelar_depan')
                            ->label('Gelar depan')
                            ->placeholder('Dra.')
                            ->maxLength(50),
                        TextInput::make('gelar_belakang')
                            ->label('Gelar belakang')
                            ->placeholder('S.Pd., Gr.')
                            ->maxLength(100),
                        TextInput::make('nip')
                            ->label('NIP')
                            ->helperText('18 angka. Boleh kosong.')
                            ->maxLength(18),
                        TextInput::make('nuptk')
                            ->label('NUPTK')
                            ->helperText('16 angka. Boleh kosong.')
                            ->maxLength(16),
                        TextInput::make('kode')
                            ->label('Kode')
                            ->helperText('1 sampai 10 huruf atau angka, misalnya kode guru di jadwal. Disimpan dengan huruf besar.')
                            ->maxLength(10),
                        Toggle::make('sertifikasi')
                            ->label('Sudah sertifikasi')
                            ->default(false)
                            ->live(),
                        TextInput::make('tahun_sertifikasi')
                            ->label('Tahun sertifikasi')
                            ->numeric()
                            ->minValue(1000)
                            ->maxValue(fn (): int => now()->year)
                            ->visible(fn (Get $get): bool => (bool) $get('sertifikasi')),
                    ]),
            ]);
    }

    /**
     * The form state of a stored staff member: the staff fields plus the
     * account and biodata, with the NIK left empty.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillFromRecord(Ptk $ptk, array $data): array
    {
        $user = $ptk->user;

        return [
            ...$data,
            'jenis_ptk' => $ptk->jenis_ptk->value,
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
        ];
    }

    /**
     * The account the biodata belongs to: the record's account, or on create
     * the chosen existing account when it may be linked (the state can be
     * tampered with, so the masked NIK and photo of another account are
     * never shown).
     */
    private static function account(?Ptk $record, Get $get): ?User
    {
        if ($record !== null) {
            return $record->user;
        }

        return $get('akun') === 'ada' ? self::linkableAccount($get('user_id')) : null;
    }

    /**
     * The account whose stored NIK the form may hint at (masked NIK as
     * placeholder, "keep the stored NIK" help text, Hapus NIK toggle): only
     * when the signed-in user may reveal that NIK (UserPolicy::viewNik). An
     * operator viewing a super_admin's or another operator's record learns
     * neither the masked NIK nor whether there is one.
     */
    private static function nikAccount(?Ptk $record, Get $get): ?User
    {
        $user = self::account($record, $get);

        return $user !== null && Filament::auth()->user()?->can('viewNik', $user) ? $user : null;
    }

    /**
     * The account $userId when it has no staff record and the signed-in user
     * may manage it, otherwise null.
     */
    private static function linkableAccount(mixed $userId): ?User
    {
        $user = filled($userId) ? User::query()->whereDoesntHave('ptk')->find($userId) : null;

        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        return $user !== null && app(UserService::class)->canManage($pelaku, $user) ? $user : null;
    }

    /**
     * Accounts without a staff record that the signed-in user may manage.
     * The accounts a non super_admin may not manage are left out in the query
     * (before the limit), and canManage is checked again on the result.
     * % and _ in the search are matched literally.
     *
     * @return array<int, string>
     */
    private static function accountsToLink(string $search): array
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();
        $userService = app(UserService::class);

        $pola = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        return User::query()
            ->whereDoesntHave('ptk')
            ->when(! $pelaku->isSuperAdmin(), fn ($query) => $query
                ->withoutRole(Peran::SuperAdmin->value)
                ->where(fn ($query) => $query
                    ->withoutRole(Peran::Operator->value)
                    ->orWhereKey($pelaku->getKey())))
            ->where(fn ($query) => $query
                ->where('name', 'like', $pola)
                ->orWhere('username', 'like', $pola))
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->filter(fn (User $user): bool => $userService->canManage($pelaku, $user))
            ->mapWithKeys(fn (User $user): array => [$user->getKey() => (string) self::accountLabel($user)])
            ->all();
    }

    /**
     * Empty the account and biodata fields, so data copied from a previously
     * chosen account never stays behind after the choice changes.
     */
    private static function clearAccount(Set $set): void
    {
        foreach (['name', 'username', 'email', 'nik', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'agama', 'alamat', 'no_hp'] as $field) {
            $set($field, null);
        }

        $set('foto', []);
    }

    private static function accountLabel(?User $user): ?string
    {
        return $user === null ? null : "{$user->name} ({$user->username})";
    }

    /**
     * Copy the chosen account into the form, so the existing biodata is kept
     * unless it is changed. The NIK stays empty (its placeholder shows the
     * masked one). PtkService checks again that the account may be linked.
     */
    private static function fillFromAccount(mixed $userId, Set $set): void
    {
        $user = self::linkableAccount($userId);

        if ($user === null) {
            self::clearAccount($set);

            return;
        }

        $set('name', $user->name);
        $set('username', $user->username);
        $set('email', $user->email);
        $set('nik', null);
        $set('jenis_kelamin', $user->jenis_kelamin?->value);
        $set('tempat_lahir', $user->tempat_lahir);
        $set('tanggal_lahir', $user->tanggal_lahir?->toDateString());
        $set('agama', $user->agama?->value);
        $set('alamat', $user->alamat);
        $set('no_hp', $user->no_hp);
        $set('foto', $user->foto === null ? [] : [(string) Str::uuid() => $user->foto]);
    }
}
