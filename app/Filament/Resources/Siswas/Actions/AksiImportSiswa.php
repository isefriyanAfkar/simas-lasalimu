<?php

namespace App\Filament\Resources\Siswas\Actions;

use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PelanggaranAturanAkademik;
use App\Domains\Academic\Services\PeriodeAkademik;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Models\ImportSiswa;
use App\Domains\People\Models\Siswa;
use App\Domains\People\Services\Import\KolomImportSiswa;
use App\Domains\People\Services\Import\TemplateImportSiswa;
use App\Domains\People\Services\ImportSiswaService;
use App\Domains\People\Services\PelanggaranAturanKesiswaan;
use App\Filament\Resources\Siswas\Pages\ImportSiswaPage;
use App\Filament\Support\GalatValidasi;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The import actions of the Siswa list: download the template, upload a
 * file (which queues its check and opens the import page), and go back to
 * an import that is not over. They only call ImportSiswaService and
 * TemplateImportSiswa. All need the Import:Siswa permission
 * (SiswaPolicy::import).
 *
 * The upload is refused in the browser above ImportSiswaService::
 * MAKS_UKURAN_KB, before it is sent, so a file above the web server's
 * limits never reaches it; the service checks the size again.
 */
final class AksiImportSiswa
{
    /**
     * Browsers report .xlsx with its own type; some systems send a generic
     * one. The reader refuses anything that is not a real .xlsx.
     *
     * @var list<string>
     */
    private const array JENIS_FILE = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream',
        'application/zip',
    ];

    public static function unduhTemplate(): Action
    {
        return Action::make('unduhTemplateImport')
            ->label('Unduh template')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading('Unduh template import siswa')
            ->modalDescription('Daftar rombel di template mengikuti tahun ajaran yang dipilih.')
            ->modalSubmitActionLabel('Unduh')
            ->schema([self::pilihTahunAjaran()])
            ->authorize(fn (): bool => self::pelaku()->can('import', Siswa::class))
            ->action(function (array $data): ?BinaryFileResponse {
                $tahunAjaran = self::tahunAjaran($data['tahun_ajaran_id'] ?? null);

                if ($tahunAjaran === null) {
                    Notification::make()->danger()->title('Pilih tahun ajaran aktif atau sesudahnya.')->send();

                    return null;
                }

                $path = app(TemplateImportSiswa::class)->fileSementara($tahunAjaran);

                return response()
                    ->download($path, 'template-import-siswa-'.Str::slug($tahunAjaran->nama).'.xlsx', ['Content-Type' => ImportSiswaService::JENIS_XLSX])
                    ->deleteFileAfterSend();
            });
    }

    public static function import(): Action
    {
        return Action::make('importSiswa')
            ->label('Import')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Import siswa dari Excel')
            ->modalDescription('Seluruh file diperiksa dulu di latar belakang. Tidak ada yang tersimpan sebelum Anda melihat hasilnya dan menekan Simpan.')
            ->modalSubmitActionLabel('Unggah dan periksa')
            ->schema([
                self::pilihTahunAjaran(),
                FileUpload::make('file')
                    ->label('File Excel (.xlsx)')
                    ->disk(ImportSiswaService::DISK)
                    ->directory(ImportSiswaService::FOLDER)
                    ->visibility('private')
                    ->acceptedFileTypes(self::JENIS_FILE)
                    ->maxSize(ImportSiswaService::MAKS_UKURAN_KB)
                    ->storeFileNamesIn('nama_file')
                    ->getUploadedFileNameForStorageUsing(fn (): string => Str::uuid()->toString().'.xlsx')
                    ->helperText(sprintf('Pakai template dari tombol "Unduh template". Paling besar %d MB dan %d baris.', ImportSiswaService::MAKS_UKURAN_KB / 1024, KolomImportSiswa::MAKS_BARIS))
                    ->validationMessages(['max' => ImportSiswaService::pesanTerlaluBesar()])
                    ->required(),
            ])
            ->authorize(fn (): bool => self::pelaku()->can('import', Siswa::class))
            ->action(function (array $data, Action $action) {
                $tahunAjaran = self::tahunAjaran($data['tahun_ajaran_id'] ?? null);

                if ($tahunAjaran === null) {
                    Notification::make()->danger()->title('Pilih tahun ajaran aktif atau sesudahnya.')->send();

                    return null;
                }

                try {
                    $import = app(ImportSiswaService::class)->mulai(self::pelaku(), $tahunAjaran, (string) ($data['file'] ?? ''), (string) ($data['nama_file'] ?? ''));
                } catch (ValidationException $exception) {
                    throw GalatValidasi::prefix($exception, 'mountedActions.'.($action->getNestingIndex() ?? 0).'.data');
                } catch (PelanggaranAturanAkademik|PelanggaranAturanKesiswaan $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->persistent()->send();

                    return null;
                }

                return redirect(ImportSiswaPage::getUrl(['import' => $import]));
            });
    }

    /**
     * Back to the newest import that is not over.
     */
    public static function lanjutkan(): Action
    {
        return Action::make('importBerjalan')
            ->label(fn (): string => 'Import berjalan: '.(self::berjalan()->nama_file ?? ''))
            ->icon(Heroicon::OutlinedClock)
            ->color('warning')
            ->url(fn (): ?string => ($import = self::berjalan()) === null ? null : ImportSiswaPage::getUrl(['import' => $import]))
            ->authorize(fn (): bool => self::pelaku()->can('import', Siswa::class))
            ->visible(fn (): bool => self::berjalan() !== null);
    }

    private static function pilihTahunAjaran(): Select
    {
        return Select::make('tahun_ajaran_id')
            ->label('Tahun ajaran')
            ->options(fn (): array => app(ImportSiswaService::class)->opsiTahunAjaran())
            ->default(fn (): ?int => app(PeriodeAkademik::class)->tahunAjaranAktif()?->getKey())
            ->helperText('Tahun ajaran aktif atau sesudahnya. Rombel di file harus rombel tahun ajaran ini.')
            ->required();
    }

    private static function tahunAjaran(mixed $id): ?TahunAjaran
    {
        if (! array_key_exists((int) $id, app(ImportSiswaService::class)->opsiTahunAjaran())) {
            return null;
        }

        return TahunAjaran::query()->find((int) $id);
    }

    private static function berjalan(): ?ImportSiswa
    {
        return app(ImportSiswaService::class)->belumBerakhir()->first();
    }

    private static function pelaku(): User
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        return $pelaku;
    }
}
