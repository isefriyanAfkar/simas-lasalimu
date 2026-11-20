<?php

namespace App\Filament\Resources\Siswas\Pages;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\StatusImportSiswa;
use App\Domains\People\Models\ImportSiswa;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Services\Import\HasilImportSiswa;
use App\Domains\People\Services\Import\KolomImportSiswa;
use App\Domains\People\Services\ImportSiswaService;
use App\Domains\People\Services\PelanggaranAturanKesiswaan;
use App\Filament\Resources\Siswas\SiswaResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * One student import: its status, refreshed every few seconds while it waits
 * or runs, then the result of the check (counts, errors per row, rombel
 * moves) with Simpan (confirmed), the error file and Batal. Every action
 * only calls ImportSiswaService. Needs the Import:Siswa permission.
 */
class ImportSiswaPage extends Page
{
    /**
     * At most this many errors are listed on the page; the error file holds
     * all of them.
     */
    public const int MAKS_GALAT_DITAMPILKAN = 200;

    /**
     * @var array<string, string>
     */
    public const array LABEL_RINGKASAN = [
        'siswa_baru' => 'Siswa baru',
        'siswa_diperbarui' => 'Siswa diperbarui',
        'siswa_dipindah' => 'Dipindah rombel',
        'siswa_tanpa_perubahan' => 'Tanpa perubahan',
        'wali_baru' => 'Wali baru',
        'wali_dipakai_bersama' => 'Wali dipakai bersama',
        'baris_bergalat' => 'Baris bergalat',
    ];

    protected static string $resource = SiswaResource::class;

    protected string $view = 'filament.resources.siswas.pages.import-siswa';

    public ImportSiswa $importSiswa;

    public function mount(int|string $import): void
    {
        abort_unless(self::pelaku()->can('import', Siswa::class), 403);

        $this->importSiswa = ImportSiswa::query()->with(['tahunAjaran', 'user'])->findOrFail($import);
    }

    public function getTitle(): string
    {
        return 'Import siswa';
    }

    /**
     * Polled while the import waits or runs (see the view).
     */
    public function segarkan(): void
    {
        $this->importSiswa->refresh();
    }

    public function sedangBerjalan(): bool
    {
        return in_array($this->importSiswa->status, [StatusImportSiswa::Menunggu, StatusImportSiswa::Memeriksa, StatusImportSiswa::Menyimpan], true);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->record($this->importSiswa)
            ->components([
                Section::make('Status')
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 4])->schema([
                            TextEntry::make('tahunAjaran.nama')->label('Tahun ajaran'),
                            TextEntry::make('nama_file')->label('File'),
                            TextEntry::make('user.name')->label('Diunggah oleh'),
                            TextEntry::make('status')
                                ->label('Status')
                                ->badge()
                                ->formatStateUsing(fn (StatusImportSiswa $state): string => $state->label())
                                ->color(fn (StatusImportSiswa $state): string => match ($state) {
                                    StatusImportSiswa::Siap, StatusImportSiswa::Selesai => 'success',
                                    StatusImportSiswa::Bergalat, StatusImportSiswa::Gagal => 'danger',
                                    StatusImportSiswa::Dibatalkan => 'gray',
                                    default => 'warning',
                                }),
                        ]),
                        TextEntry::make('pesan')->label('Keterangan')->visible(fn (): bool => filled($this->importSiswa->pesan)),
                        Text::make(fn (): string => $this->keteranganStatus())->visible(fn (): bool => $this->keteranganStatus() !== ''),
                    ]),
                Section::make('Ringkasan')
                    ->visible(fn (): bool => $this->importSiswa->ringkasan !== null)
                    ->schema([
                        Grid::make(['default' => 2, 'md' => 4])->schema(array_map(
                            fn (string $kunci): TextEntry => TextEntry::make("ringkasan.{$kunci}")->label(self::LABEL_RINGKASAN[$kunci])->default(0),
                            HasilImportSiswa::RINGKASAN,
                        )),
                    ]),
                Section::make('Galat')
                    ->description(fn (): string => $this->keteranganGalat())
                    ->visible(fn (): bool => ($this->importSiswa->galat ?? []) !== [])
                    ->schema([
                        RepeatableEntry::make('galat')
                            ->hiddenLabel()
                            ->state(fn (): array => array_slice($this->importSiswa->galat ?? [], 0, self::MAKS_GALAT_DITAMPILKAN))
                            ->table([
                                TableColumn::make('Baris'),
                                TableColumn::make('Kolom'),
                                TableColumn::make('Galat'),
                            ])
                            ->schema([
                                TextEntry::make('baris')->placeholder('File'),
                                TextEntry::make('kolom')
                                    ->formatStateUsing(fn (?string $state): string => $state === null || ! isset(KolomImportSiswa::KOLOM[$state]) ? '' : KolomImportSiswa::judul($state))
                                    ->placeholder('-'),
                                TextEntry::make('pesan'),
                            ]),
                    ]),
                Section::make('Pindah rombel')
                    ->description('Siswa ini sudah punya rombel lain di tahun ajaran yang sama dan akan dipindah saat disimpan.')
                    ->visible(fn (): bool => ($this->importSiswa->pindah ?? []) !== [])
                    ->schema([
                        RepeatableEntry::make('pindah')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Baris'),
                                TableColumn::make('NIS'),
                                TableColumn::make('Nama'),
                                TableColumn::make('Dari'),
                                TableColumn::make('Ke'),
                            ])
                            ->schema([
                                TextEntry::make('baris'),
                                TextEntry::make('nis'),
                                TextEntry::make('nama'),
                                TextEntry::make('dari'),
                                TextEntry::make('ke'),
                            ]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('unduhGalat')
                ->label('Unduh file galat')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => $this->importSiswa->path !== null && collect($this->importSiswa->galat ?? [])->contains(fn (array $galat): bool => $galat['baris'] !== null))
                ->action(function (): ?BinaryFileResponse {
                    $path = app(ImportSiswaService::class)->fileGalat($this->importSiswa);

                    if ($path === null) {
                        Notification::make()->danger()->title('File galat tidak tersedia.')->send();

                        return null;
                    }

                    return response()->download($path, 'galat-'.$this->importSiswa->nama_file)->deleteFileAfterSend();
                }),
            Action::make('simpan')
                ->label('Simpan')
                ->icon(Heroicon::OutlinedCheck)
                ->requiresConfirmation()
                ->modalHeading('Simpan import?')
                ->modalDescription(fn (): string => $this->ringkasanSimpan())
                ->modalSubmitActionLabel('Ya, simpan')
                ->visible(fn (): bool => $this->importSiswa->status === StatusImportSiswa::Siap)
                ->action(function (): void {
                    try {
                        $this->importSiswa = app(ImportSiswaService::class)->antrekanSimpan($this->importSiswa);
                    } catch (PelanggaranAturanKesiswaan $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                        $this->segarkan();

                        return;
                    }

                    Notification::make()->success()->title('Import sedang disimpan.')->send();
                }),
            Action::make('batal')
                ->label('Batalkan import')
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Batalkan import?')
                ->modalDescription('File yang diunggah dihapus. Tidak ada data siswa yang berubah.')
                ->modalSubmitActionLabel('Ya, batalkan')
                ->visible(fn (): bool => in_array($this->importSiswa->status, [StatusImportSiswa::Menunggu, StatusImportSiswa::Bergalat, StatusImportSiswa::Siap], true))
                ->action(function (): void {
                    try {
                        $this->importSiswa = app(ImportSiswaService::class)->batal($this->importSiswa);
                    } catch (PelanggaranAturanKesiswaan $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                        $this->segarkan();

                        return;
                    }

                    Notification::make()->success()->title('Import dibatalkan.')->send();
                }),
        ];
    }

    private function keteranganStatus(): string
    {
        return match ($this->importSiswa->status) {
            StatusImportSiswa::Menunggu => $this->importSiswa->created_at?->lt(now()->subMinutes(3))
                ? 'Belum diproses setelah 3 menit. Antrean dijalankan cron setiap menit; minta pengelola server memeriksa cron (lihat panduan pemasangan).'
                : 'Menunggu giliran di antrean. Halaman ini diperbarui sendiri.',
            StatusImportSiswa::Memeriksa => 'Seluruh file sedang diperiksa. Halaman ini diperbarui sendiri.',
            StatusImportSiswa::Menyimpan => 'Data sedang disimpan sekaligus. Halaman ini diperbarui sendiri.',
            StatusImportSiswa::Bergalat => 'Tidak ada yang tersimpan. Perbaiki file (unduh file galat untuk melihat baris yang salah), batalkan import ini, lalu unggah lagi.',
            StatusImportSiswa::Siap => 'Semua baris benar. Periksa ringkasan dan daftar pindah rombel, lalu tekan Simpan.',
            default => '',
        };
    }

    private function keteranganGalat(): string
    {
        $jumlah = count($this->importSiswa->galat ?? []);

        return $jumlah > self::MAKS_GALAT_DITAMPILKAN
            ? sprintf('Menampilkan %d dari %d galat. Unduh file galat untuk daftar lengkap.', self::MAKS_GALAT_DITAMPILKAN, $jumlah)
            : "{$jumlah} galat.";
    }

    private function ringkasanSimpan(): string
    {
        $ringkasan = $this->importSiswa->ringkasan ?? [];

        return sprintf(
            '%d siswa baru, %d diperbarui, %d dipindah rombel, %d wali baru. Semua baris disimpan sekaligus dan tercatat di log aktivitas.',
            $ringkasan['siswa_baru'] ?? 0,
            $ringkasan['siswa_diperbarui'] ?? 0,
            $ringkasan['siswa_dipindah'] ?? 0,
            $ringkasan['wali_baru'] ?? 0,
        );
    }

    private static function pelaku(): User
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        return $pelaku;
    }
}
