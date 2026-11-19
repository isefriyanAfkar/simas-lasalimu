<?php

namespace App\Filament\Resources\Siswas\RelationManagers;

use App\Domains\Foundation\Enums\JenisKelamin;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\People\Enums\HubunganWali;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Models\SiswaWali;
use App\Domains\People\Models\WaliMurid;
use App\Domains\People\Services\PelanggaranAturanKesiswaan;
use App\Domains\People\Services\SiswaWaliService;
use App\Domains\People\Services\WaliMuridService;
use App\Filament\Support\GalatValidasi;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The student's parents and guardians. Every action only calls
 * SiswaWaliService, which decides the rules. On the view page this section
 * is read only (Filament hides the actions there).
 *
 * "Tambah" either picks an existing parent (search by name or phone number
 * in any format; one parent may have several children) or creates a new
 * one. For a new parent whose phone number or NIK matches an existing one,
 * the form warns before saving and offers "Pakai wali ini" or "Tetap buat
 * wali baru"; it never blocks.
 */
class WaliRelationManager extends RelationManager
{
    protected static string $relationship = 'siswaWali';

    protected static ?string $title = 'Wali murid';

    protected static ?string $modelLabel = 'wali murid';

    /**
     * Whoever may view the student and the parent list.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can('view', $ownerRecord) && $user->can('viewAny', WaliMurid::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('waliMurid.user')
                ->orderByDesc('utama')
                ->orderBy('id'))
            ->paginated(false)
            ->columns([
                TextColumn::make('waliMurid.user.name')
                    ->label('Nama')
                    ->description(fn (SiswaWali $record): ?string => $record->waliMurid->meninggal ? 'Meninggal' : null),
                TextColumn::make('hubungan')
                    ->label('Hubungan')
                    ->formatStateUsing(fn (HubunganWali $state): string => $state->label()),
                TextColumn::make('utama')
                    ->label('Utama')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Utama' : '—')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('no_hp')
                    ->label('Nomor HP')
                    ->state(fn (SiswaWali $record): ?string => $record->waliMurid->user->noHpTersamar())
                    ->placeholder('—'),
            ])
            ->headerActions([
                $this->tambahAction(),
            ])
            ->recordActions([
                Action::make('ubahHubungan')
                    ->label('Ubah hubungan')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->fillForm(fn (SiswaWali $record): array => ['hubungan' => $record->hubungan->value])
                    ->schema([
                        Select::make('hubungan')
                            ->label('Hubungan')
                            ->options(HubunganWali::options())
                            ->required(),
                    ])
                    ->visible(fn (): bool => ! $this->isReadOnly())
                    ->authorize(fn (): bool => $this->bolehKelola())
                    ->action(function (SiswaWali $record, array $data, Action $action): void {
                        $this->jalankan(
                            fn (SiswaWaliService $service, User $pelaku) => $service->ubahHubungan($pelaku, $record, $data),
                            'Hubungan diubah',
                            $action,
                        );
                    }),
                Action::make('jadikanUtama')
                    ->label('Jadikan utama')
                    ->icon(Heroicon::OutlinedStar)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Wali utama yang sekarang tetap terhubung, tetapi bukan lagi wali utama.')
                    ->hidden(fn (SiswaWali $record): bool => $record->utama || $record->waliMurid->meninggal)
                    ->visible(fn (): bool => ! $this->isReadOnly())
                    ->authorize(fn (): bool => $this->bolehKelola())
                    ->action(function (SiswaWali $record): void {
                        $this->jalankan(
                            fn (SiswaWaliService $service, User $pelaku) => $service->jadikanUtama($pelaku, $record),
                            "{$record->waliMurid->user->name} menjadi wali utama",
                        );
                    }),
                Action::make('lepas')
                    ->label('Lepas')
                    ->icon(Heroicon::OutlinedLinkSlash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Hubungan dengan siswa ini dilepas. Data wali dan anak-anaknya yang lain tetap ada.')
                    ->hidden(fn (SiswaWali $record): bool => $record->utama)
                    ->visible(fn (): bool => ! $this->isReadOnly())
                    ->authorize(fn (): bool => $this->bolehKelola())
                    ->action(function (SiswaWali $record): void {
                        $this->jalankan(
                            fn (SiswaWaliService $service, User $pelaku) => $service->lepas($pelaku, $record),
                            "{$record->waliMurid->user->name} dilepas",
                        );
                    }),
            ]);
    }

    private function tambahAction(): Action
    {
        return Action::make('tambah')
            ->label('Tambah wali')
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading('Tambah wali murid')
            ->modalSubmitActionLabel('Simpan')
            ->schema([
                ToggleButtons::make('wali')
                    ->label('Wali')
                    ->options(['ada' => 'Wali yang sudah ada', 'baru' => 'Wali baru'])
                    ->default('ada')
                    ->inline()
                    ->live()
                    ->required(),
                Select::make('wali_murid_id')
                    ->label('Cari wali')
                    ->helperText('Cari nama atau nomor HP (08…, +62…). Satu wali bisa punya beberapa anak.')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => app(WaliMuridService::class)->cari($search)
                        ->mapWithKeys(fn (WaliMurid $wali): array => [$wali->getKey() => self::labelWali($wali)])
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) && ($wali = WaliMurid::query()->with(['user', 'siswaWali.siswa.user'])->find((int) $value)) !== null ? self::labelWali($wali) : null)
                    ->visible(fn (Get $get): bool => $get('wali') === 'ada')
                    ->required(fn (Get $get): bool => $get('wali') === 'ada'),
                TextInput::make('name')
                    ->label('Nama')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('wali') === 'baru')
                    ->required(fn (Get $get): bool => $get('wali') === 'baru'),
                Select::make('jenis_kelamin')
                    ->label('Jenis kelamin')
                    ->options(JenisKelamin::options())
                    ->helperText('Boleh dikosongkan: ayah menjadi laki-laki dan ibu perempuan.')
                    ->visible(fn (Get $get): bool => $get('wali') === 'baru'),
                TextInput::make('no_hp')
                    ->label('Nomor HP')
                    ->maxLength(20)
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => $get('wali') === 'baru'),
                TextInput::make('nik')
                    ->label('NIK')
                    ->maxLength(16)
                    ->autocomplete(false)
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => $get('wali') === 'baru'),
                TextInput::make('pekerjaan')
                    ->label('Pekerjaan')
                    ->maxLength(100)
                    ->visible(fn (Get $get): bool => $get('wali') === 'baru'),
                Textarea::make('alamat')
                    ->label('Alamat')
                    ->maxLength(1000)
                    ->visible(fn (Get $get): bool => $get('wali') === 'baru'),
                TextEntry::make('peringatan_ganda')
                    ->hiddenLabel()
                    ->state('Nomor HP atau NIK ini sama dengan wali yang sudah ada. Mungkin orangnya sama.')
                    ->color('warning')
                    ->visible(fn (Get $get): bool => self::calonGanda($get) !== []),
                Radio::make('pakai_wali')
                    ->label('Pilih')
                    ->options(fn (Get $get): array => self::calonGanda($get) + ['baru' => 'Tetap buat wali baru'])
                    ->visible(fn (Get $get): bool => self::calonGanda($get) !== [])
                    ->required(fn (Get $get): bool => self::calonGanda($get) !== []),
                Select::make('hubungan')
                    ->label('Hubungan')
                    ->options(HubunganWali::options())
                    ->required(),
            ])
            ->visible(fn (): bool => ! $this->isReadOnly())
            ->authorize(fn (): bool => $this->bolehKelola())
            ->action(function (array $data, Action $action): void {
                if (($data['wali'] ?? null) === 'baru' && is_numeric($data['pakai_wali'] ?? null)) {
                    $data = ['wali' => 'ada', 'wali_murid_id' => (int) $data['pakai_wali'], 'hubungan' => $data['hubungan'] ?? null];
                }

                $this->jalankan(
                    fn (SiswaWaliService $service, User $pelaku) => $service->tambahkan($pelaku, $this->siswa(), $data),
                    'Wali murid ditambahkan',
                    $action,
                );
            });
    }

    /**
     * For the "Wali baru" form: existing parents with the same phone number
     * (any format) or NIK, as [id => "Pakai Ahmad (anak: Budi, Ani)"].
     *
     * @return array<int|string, string>
     */
    private static function calonGanda(Get $get): array
    {
        if ($get('wali') !== 'baru') {
            return [];
        }

        $noHp = $get('no_hp');
        $nik = $get('nik');

        return app(WaliMuridService::class)
            ->calonGanda(is_string($noHp) ? $noHp : null, is_string($nik) ? $nik : null)
            ->mapWithKeys(fn (WaliMurid $wali): array => [$wali->getKey() => 'Pakai '.self::labelWali($wali)])
            ->all();
    }

    /**
     * "Ahmad · 0812*****7890 · anak: Budi, Ani".
     */
    private static function labelWali(WaliMurid $wali): string
    {
        $anak = $wali->siswaWali->map(fn (SiswaWali $siswaWali): string => $siswaWali->siswa->user->name)->sort()->implode(', ');

        return implode(' · ', array_filter([
            $wali->user->name.($wali->meninggal ? ' (meninggal)' : ''),
            $wali->user->noHpTersamar(),
            $anak === '' ? null : "anak: {$anak}",
        ]));
    }

    private function siswa(): Siswa
    {
        /** @var Siswa $siswa */
        $siswa = $this->getOwnerRecord();

        return $siswa;
    }

    /**
     * Filament's read-only mode on the view page hides only its own create,
     * edit and delete actions, so the custom actions check it here.
     */
    private function bolehKelola(): bool
    {
        $user = Filament::auth()->user();

        return ! $this->isReadOnly() && $user instanceof User && $user->can('kelolaWali', $this->siswa());
    }

    /**
     * Run a SiswaWaliService call: field errors go to the action form,
     * rule violations become a notification.
     *
     * @param  Closure(SiswaWaliService, User): mixed  $operasi
     */
    private function jalankan(Closure $operasi, string $pesanBerhasil, ?Action $action = null): void
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        try {
            $operasi(app(SiswaWaliService::class), $pelaku);
        } catch (ValidationException $exception) {
            if ($action === null) {
                Notification::make()->danger()->title(collect($exception->errors())->flatten()->first() ?? $exception->getMessage())->send();

                return;
            }

            throw GalatValidasi::prefix($exception, 'mountedActions.'.($action->getNestingIndex() ?? 0).'.data');
        } catch (PelanggaranAturanKesiswaan|PelanggaranAturanPengguna $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($pesanBerhasil)->send();
    }
}
