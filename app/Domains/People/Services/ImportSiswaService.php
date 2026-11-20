<?php

namespace App\Domains\People\Services;

use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PelanggaranAturanAkademik;
use App\Domains\Academic\Services\PeriodeAkademik;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\IsolasiSnapshot;
use App\Domains\Foundation\Services\UserService;
use App\Domains\People\Enums\AksiKesiswaan;
use App\Domains\People\Enums\StatusImportSiswa;
use App\Domains\People\Models\ImportSiswa;
use App\Domains\People\Services\Import\BacaFileImportSiswa;
use App\Domains\People\Services\Import\HasilImportSiswa;
use App\Domains\People\Services\Import\KolomImportSiswa;
use App\Domains\People\Services\Import\PeriksaImportSiswa;
use App\Domains\People\Services\Import\ProsesImportSiswa;
use App\Domains\People\Services\Import\SimpanImportSiswa;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use RuntimeException;
use Throwable;

/**
 * The student import from an Excel file, all or nothing. The check and the
 * save run as queued jobs (PeriksaImportSiswa, SimpanImportSiswa), never in
 * a web request.
 *
 * - mulai(): an uploaded file in FOLDER on the private DISK, at most
 *   MAKS_UKURAN_KB, becomes an import for a school year (the active one or a
 *   later one), and its check is queued. One import per school year at a
 *   time: while one is not over (selesai, dibatalkan, gagal), another is
 *   refused.
 * - pratinjau(): the check. The file is read (BacaFileImportSiswa) and every
 *   row applied through the services (ProsesImportSiswa) inside one
 *   transaction that is always rolled back; only the result is stored on
 *   the import (siap, or bergalat with its errors).
 * - antrekanSimpan() then simpan(): only from siap. The rows are applied
 *   again in one transaction, committed only when no row has an error and
 *   the result is the one the operator confirmed, together with one log
 *   entry for the whole import; the file is deleted after the commit. An
 *   error that appears now saves nothing and sends the import back to
 *   bergalat; another result (the data changed since the check) saves
 *   nothing and sends it back to siap, to be confirmed again.
 * - batal(): from menunggu, bergalat or siap; deletes the file.
 * - tandaiGagal(): a job that stopped; bersihkan(): the scheduled cleanup.
 *
 * Neither run names whoever holds a NIK, whatever the operator may see
 * elsewhere, nor logs a NIK lookup (UserService::tanpaMenyebutPemilikNik).
 *
 * Effects outside the database transaction, checked for the paths the import
 * uses:
 * - the file is only read; it is deleted after a save commits, on batal, on
 *   gagal and by the cleanup;
 * - UserService registers a Cari NIK log entry after a rollback or commit
 *   only when it names a NIK holder, which it never does here; it deletes a
 *   replaced photo after a commit, and the import never sends a photo;
 * - a rolled-back check still uses up AUTO_INCREMENT values (gaps in ids),
 *   and may warm the permission cache with what the database already holds.
 * The services fire no other events, jobs or file writes.
 *
 * The check runs in the name of the operator who uploaded the file; the
 * save, the cancel and every error file download in the name of whoever
 * pressed the button, each with a log entry.
 *
 * Every status change is one conditional UPDATE on the status it expects
 * (ubahJikaMasih()): of an operator, a job and the cleanup moving one
 * import at once, exactly one wins, and the others leave it as it is.
 */
class ImportSiswaService
{
    public const string DISK = 'local';

    public const string FOLDER = 'imports';

    /**
     * 1000 rows with every column filled take about 160 KB, so 2 MB leaves
     * room for formatting while staying far below the web server limits
     * (upload_max_filesize and post_max_size 256M).
     */
    public const int MAKS_UKURAN_KB = 2048;

    /**
     * Timeout of a check or save job, in seconds. 500 rows took about 20 s
     * on the development laptop; 15 minutes leaves room for 1000 rows on a
     * slower shared server. The queue's retry_after must stay above it
     * (config/queue.php).
     */
    public const int BATAS_WAKTU_JOB = 900;

    /**
     * An import still being checked or saved this long after it started has
     * a job that died without being reported: twice the job timeout.
     */
    public const int BATAS_MACET_DETIK = 2 * self::BATAS_WAKTU_JOB;

    /**
     * A queued import not picked up for this long means the queue does not
     * run; one waiting for the operator this long is cancelled; a file left
     * in FOLDER without an import this long is deleted.
     */
    public const int BATAS_TERLANTAR_JAM = 24;

    public const string PESAN_FILE_TIDAK_ADA = 'File import tidak ditemukan. Unggah ulang file .xlsx.';

    public const string PESAN_PERIKSA_GAGAL = 'Pemeriksaan berhenti sebelum selesai. Tidak ada data yang berubah. Unggah ulang file untuk mencoba lagi.';

    public const string PESAN_SIMPAN_GAGAL = 'Penyimpanan berhenti sebelum selesai. Tidak ada data siswa yang tersimpan. Unggah ulang file untuk mencoba lagi.';

    public const string PESAN_MACET = 'Proses berhenti di tengah jalan (misalnya diputus server). Tidak ada data siswa yang tersimpan. Unggah ulang file untuk mencoba lagi.';

    public const string PESAN_ANTREAN_MATI = 'Antrean tidak berjalan selama 24 jam, jadi file ini tidak pernah diproses. Minta pengelola server memeriksa cron, lalu unggah ulang file.';

    public const string PESAN_TERLANTAR = 'Dibatalkan otomatis: tidak dilanjutkan dalam 24 jam.';

    public const string PESAN_BERUBAH = 'Data di sistem berubah sejak pemeriksaan, jadi hasilnya berbeda dan tidak ada yang disimpan. Periksa ringkasan dan daftar pindah rombel yang baru, lalu tekan Simpan lagi.';

    public const string JENIS_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private BacaFileImportSiswa $bacaFile,
        private ProsesImportSiswa $proses,
        private PeriodeAkademik $periodeAkademik,
    ) {}

    /**
     * The school years an import may target: the active one and later ones,
     * id => name, earliest first. Empty without an active school year.
     *
     * @return array<int, string>
     */
    public function opsiTahunAjaran(): array
    {
        $aktif = $this->periodeAkademik->tahunAjaranAktif();

        if ($aktif === null) {
            return [];
        }

        return TahunAjaran::query()
            ->whereDate('tanggal_mulai', '>=', $aktif->tanggal_mulai->toDateString())
            ->orderBy('tanggal_mulai')
            ->pluck('nama', 'id')
            ->all();
    }

    /**
     * Imports that are not over, newest first.
     *
     * @return Collection<int, ImportSiswa>
     */
    public function belumBerakhir(): Collection
    {
        return ImportSiswa::query()
            ->with('tahunAjaran')
            ->whereIn('status', self::statusBelumBerakhir())
            ->latest('id')
            ->get();
    }

    /**
     * The uploaded file is deleted when it is refused, except a file another
     * import uses, which is refused as if it did not exist and kept.
     *
     * @throws ValidationException when $path is not an uploaded .xlsx in FOLDER, is another import's file, or is too large
     * @throws PelanggaranAturanAkademik when the school year is before the active one
     * @throws PelanggaranAturanKesiswaan when another import of the school year is not over
     */
    public function mulai(User $pelaku, TahunAjaran $tahunAjaran, string $path, string $namaFile): ImportSiswa
    {
        if (! $this->fileAda($path) || ImportSiswa::query()->where('path', $path)->exists()) {
            throw ValidationException::withMessages(['file' => self::PESAN_FILE_TIDAK_ADA]);
        }

        try {
            if (Storage::disk(self::DISK)->size($path) > self::MAKS_UKURAN_KB * 1024) {
                throw ValidationException::withMessages(['file' => self::pesanTerlaluBesar()]);
            }

            $aktif = $this->periodeAkademik->tahunAjaranAktifAtauGagal();

            if ($tahunAjaran->tanggal_mulai->lt($aktif->tanggal_mulai)) {
                throw PelanggaranAturanAkademik::tahunAjaranSudahLewat($tahunAjaran->nama);
            }

            $namaFile = mb_substr(trim(basename(str_replace('\\', '/', $namaFile))), 0, 255);

            $import = DB::transaction(function () use ($pelaku, $tahunAjaran, $path, $namaFile): ImportSiswa {
                // Serializes two uploads for the same school year.
                TahunAjaran::query()->whereKey($tahunAjaran->getKey())->lockForUpdate()->first(['id']);

                $berjalan = ImportSiswa::query()
                    ->where('tahun_ajaran_id', $tahunAjaran->getKey())
                    ->whereIn('status', self::statusBelumBerakhir())
                    ->latest('id')
                    ->first();

                if ($berjalan !== null) {
                    throw PelanggaranAturanKesiswaan::importLainBerjalan($tahunAjaran->nama, $berjalan->nama_file, $berjalan->status->label());
                }

                return ImportSiswa::query()->create([
                    'user_id' => $pelaku->getKey(),
                    'tahun_ajaran_id' => $tahunAjaran->getKey(),
                    'nama_file' => $namaFile === '' ? 'import.xlsx' : $namaFile,
                    'path' => $path,
                    'status' => StatusImportSiswa::Menunggu,
                ]);
            });
        } catch (Throwable $exception) {
            $this->hapusFile($path);

            throw $exception;
        }

        PeriksaImportSiswa::dispatch((int) $import->getKey());

        return $import;
    }

    /**
     * The check: nothing but the import itself is written. It starts only
     * from menunggu, bergalat or siap, and its result is stored only while
     * the import is still memeriksa: an import another process ended in the
     * meantime (the cleanup) keeps that end.
     *
     * @throws PelanggaranAturanKesiswaan when the import is over, or being checked or saved
     */
    public function pratinjau(ImportSiswa $import): ImportSiswa
    {
        $mulai = $this->ubahJikaMasih($import, [StatusImportSiswa::Menunggu, StatusImportSiswa::Bergalat, StatusImportSiswa::Siap], [
            'status' => StatusImportSiswa::Memeriksa,
            'pesan' => null,
            'diproses_sejak' => now(),
        ]);

        if (! $mulai) {
            throw $this->ditolak($import);
        }

        $import = $this->muat($import);
        $hasil = $this->jalankan($import, $import->user, function (): void {
            // A check never keeps anything.
        });

        if ($hasil !== null) {
            $this->ubahJikaMasih($import, [StatusImportSiswa::Memeriksa], [
                'status' => $hasil->bersih() ? StatusImportSiswa::Siap : StatusImportSiswa::Bergalat,
                ...$this->hasil($hasil),
            ]);
        }

        return $import->refresh();
    }

    /**
     * Queue the save of a siap import in the name of $pelaku, who pressed
     * Simpan: it becomes menyimpan at once, so a second click is refused.
     *
     * @throws PelanggaranAturanKesiswaan when the import is not siap
     */
    public function antrekanSimpan(ImportSiswa $import, User $pelaku): ImportSiswa
    {
        $diantrekan = $this->ubahJikaMasih($import, [StatusImportSiswa::Siap], [
            'status' => StatusImportSiswa::Menyimpan,
            'pesan' => null,
            'diproses_sejak' => null,
        ]);

        if (! $diantrekan) {
            throw PelanggaranAturanKesiswaan::importBelumSiap();
        }

        SimpanImportSiswa::dispatch((int) $import->getKey(), (int) $pelaku->getKey());

        return $import->refresh();
    }

    /**
     * From siap, or from menyimpan as queued by antrekanSimpan(). Every
     * change, and the import's log entry, is made in the name of $pelaku.
     * The rows are committed only when:
     * - no row has an error (else bergalat with the new errors);
     * - the import is still menyimpan, read with a lock held until the
     *   commit: an import the cleanup or a failed job ended meanwhile keeps
     *   that end and nothing is saved, and none can end it while it commits;
     * - the counts and the rombel moves are those of the check the operator
     *   confirmed (else siap again with the new result and PESAN_BERUBAH,
     *   to be confirmed again).
     *
     * @throws PelanggaranAturanKesiswaan when the import is neither
     */
    public function simpan(ImportSiswa $import, User $pelaku): ImportSiswa
    {
        $mulai = $this->ubahJikaMasih($import, [StatusImportSiswa::Siap, StatusImportSiswa::Menyimpan], [
            'status' => StatusImportSiswa::Menyimpan,
            'pesan' => null,
            'diproses_sejak' => now(),
        ]);

        if (! $mulai) {
            throw PelanggaranAturanKesiswaan::importBelumSiap();
        }

        $import = $this->muat($import);
        $dikonfirmasi = ['ringkasan' => $import->ringkasan, 'pindah' => $import->pindah];
        $berakhir = false;

        $hasil = $this->jalankan($import, $pelaku, function (HasilImportSiswa $hasil) use ($import, $pelaku, $dikonfirmasi, &$berakhir): bool {
            if (! $hasil->bersih()) {
                return false;
            }

            $status = ImportSiswa::query()->whereKey($import->getKey())->lockForUpdate()->firstOrFail(['status'])->status;

            if ($status !== StatusImportSiswa::Menyimpan) {
                $berakhir = true;

                return false;
            }

            if ($hasil->ringkasan !== $dikonfirmasi['ringkasan'] || $hasil->pindah !== $dikonfirmasi['pindah']) {
                return false;
            }

            $path = (string) $import->path;

            activity(SiswaService::LOG_NAME)
                ->causedBy($pelaku)
                ->performedOn($import)
                ->event(AksiKesiswaan::SiswaDiimpor->value)
                ->withProperties([
                    'nama_file' => $import->nama_file,
                    'tahun_ajaran' => $import->tahunAjaran->nama,
                    'ringkasan' => $hasil->ringkasan,
                ])
                ->log(AksiKesiswaan::SiswaDiimpor->label());

            $import->update([
                'status' => StatusImportSiswa::Selesai,
                'path' => null,
                'selesai_pada' => now(),
                ...$this->hasil($hasil),
            ]);

            DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($path));
            $berakhir = true;

            return true;
        });

        if ($hasil !== null && ! $berakhir) {
            $this->ubahJikaMasih($import, [StatusImportSiswa::Menyimpan], $hasil->bersih()
                ? ['status' => StatusImportSiswa::Siap, 'pesan' => self::PESAN_BERUBAH, ...$this->hasil($hasil)]
                : ['status' => StatusImportSiswa::Bergalat, ...$this->hasil($hasil)]);
        }

        return $import->refresh();
    }

    /**
     * Cancelled by $pelaku, with a log entry naming them.
     *
     * @throws PelanggaranAturanKesiswaan when the import is over, or being checked or saved
     */
    public function batal(ImportSiswa $import, User $pelaku): ImportSiswa
    {
        $dibatalkan = $this->akhiri($import, [StatusImportSiswa::Menunggu, StatusImportSiswa::Bergalat, StatusImportSiswa::Siap], StatusImportSiswa::Dibatalkan, null, function (ImportSiswa $import) use ($pelaku): void {
            $this->catat($import, $pelaku, AksiKesiswaan::ImportDibatalkan);
        });

        if (! $dibatalkan) {
            throw $this->ditolak($import);
        }

        return $import;
    }

    /**
     * A job that stopped (PeriksaImportSiswa::failed(), SimpanImportSiswa::
     * failed()): an import still waiting, being checked or being saved
     * becomes gagal with $pesan, and its file is deleted. A save that stopped
     * rolled its transaction back, so nothing was kept.
     */
    public function tandaiGagal(int $importId, string $pesan): void
    {
        $import = ImportSiswa::query()->find($importId);

        if ($import !== null) {
            $this->akhiri($import, [StatusImportSiswa::Menunggu, StatusImportSiswa::Memeriksa, StatusImportSiswa::Menyimpan], StatusImportSiswa::Gagal, $pesan);
        }
    }

    /**
     * The scheduled cleanup (simas:bersihkan-import):
     * - checked or saved for longer than BATAS_MACET_DETIK: gagal (a job that
     *   died without failed() being called);
     * - queued for longer than BATAS_TERLANTAR_JAM: gagal (the queue does not
     *   run);
     * - siap or bergalat, untouched for BATAS_TERLANTAR_JAM: dibatalkan;
     * - a file in FOLDER older than BATAS_TERLANTAR_JAM that no import still
     *   uses: deleted.
     * Each import is ended only while it still has the status it was found
     * with: one that moved on meanwhile is left alone and not counted.
     *
     * @return array{macet: int, terlantar: int, file: int}
     */
    public function bersihkan(): array
    {
        $hasil = ['macet' => 0, 'terlantar' => 0, 'file' => 0];
        $batasMacet = now()->subSeconds(self::BATAS_MACET_DETIK);
        $batasTerlantar = now()->subHours(self::BATAS_TERLANTAR_JAM);

        $macet = ImportSiswa::query()
            ->whereIn('status', [StatusImportSiswa::Memeriksa->value, StatusImportSiswa::Menyimpan->value])
            ->where(fn ($query) => $query
                ->where('diproses_sejak', '<', $batasMacet)
                ->orWhere(fn ($query) => $query->whereNull('diproses_sejak')->where('updated_at', '<', $batasTerlantar)))
            ->get();

        foreach ($macet as $import) {
            if ($this->akhiri($import, [$import->status], StatusImportSiswa::Gagal, $import->diproses_sejak === null ? self::PESAN_ANTREAN_MATI : self::PESAN_MACET)) {
                $hasil['macet']++;
            }
        }

        foreach (ImportSiswa::query()->where('status', StatusImportSiswa::Menunggu->value)->where('updated_at', '<', $batasTerlantar)->get() as $import) {
            if ($this->akhiri($import, [StatusImportSiswa::Menunggu], StatusImportSiswa::Gagal, self::PESAN_ANTREAN_MATI)) {
                $hasil['macet']++;
            }
        }

        foreach (ImportSiswa::query()->whereIn('status', [StatusImportSiswa::Siap->value, StatusImportSiswa::Bergalat->value])->where('updated_at', '<', $batasTerlantar)->get() as $import) {
            if ($this->akhiri($import, [$import->status], StatusImportSiswa::Dibatalkan, self::PESAN_TERLANTAR)) {
                $hasil['terlantar']++;
            }
        }

        $dipakai = ImportSiswa::query()->whereNotNull('path')->pluck('path')->all();
        $disk = Storage::disk(self::DISK);

        foreach ($disk->files(self::FOLDER) as $file) {
            if (! in_array($file, $dipakai, true) && $disk->lastModified($file) < $batasTerlantar->getTimestamp() && $this->fileAda($file)) {
                $disk->delete($file);
                $hasil['file']++;
            }
        }

        return $hasil;
    }

    /**
     * The uploaded file with the error of each row in a "Keterangan galat"
     * column (reused when the file already has one) and the cells in error
     * shaded. Written to a temporary file whose path is returned; null when
     * the import has no row error or its file is gone. Only the operator's
     * own cells and the stored messages, which hold no NIK and no name of a
     * NIK holder. Every file made is logged in the name of $pelaku (the
     * import and when, never the content).
     */
    public function fileGalat(ImportSiswa $import, User $pelaku): ?string
    {
        $perBaris = [];

        foreach ($import->galat ?? [] as $galat) {
            if ($galat['baris'] !== null) {
                $perBaris[$galat['baris']][] = $galat;
            }
        }

        if ($perBaris === [] || ! $this->fileAda($import->path)) {
            return null;
        }

        $spreadsheet = IOFactory::load(Storage::disk(self::DISK)->path((string) $import->path));

        try {
            $sheet = $spreadsheet->getSheetByName(KolomImportSiswa::LEMBAR_DATA) ?? $spreadsheet->getSheet(0);
            $kolomKe = [];
            $terakhir = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

            for ($indeks = 1; $indeks <= $terakhir; $indeks++) {
                $kolomKe[mb_strtolower(trim((string) $sheet->getCell([$indeks, 1])->getValue()))] ??= $indeks;
            }

            $kolomGalat = $kolomKe[mb_strtolower(KolomImportSiswa::KOLOM_GALAT)] ?? $terakhir + 1;
            $sheet->setCellValue([$kolomGalat, 1], KolomImportSiswa::KOLOM_GALAT);
            $sheet->getStyle([$kolomGalat, 1])->getFont()->setBold(true);
            $sheet->getColumnDimensionByColumn($kolomGalat)->setWidth(60);

            for ($baris = 2; $baris <= $sheet->getHighestDataRow(); $baris++) {
                $sheet->setCellValue([$kolomGalat, $baris], null);
            }

            foreach ($perBaris as $baris => $daftar) {
                $teks = [];

                foreach ($daftar as $galat) {
                    $judul = $galat['kolom'] === null ? null : KolomImportSiswa::judul($galat['kolom']);
                    $teks[] = $judul === null ? $galat['pesan'] : "{$judul}: {$galat['pesan']}";

                    $indeks = $judul === null ? null : ($kolomKe[mb_strtolower($judul)] ?? null);

                    if ($indeks !== null) {
                        $sheet->getStyle([$indeks, $baris])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8D7DA');
                    }
                }

                $sheet->setCellValue([$kolomGalat, $baris], implode("\n", $teks));
                $sheet->getStyle([$kolomGalat, $baris])->getAlignment()->setWrapText(true);
            }

            $path = self::fileSementaraBaru('galat-import');
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $this->catat($import, $pelaku, AksiKesiswaan::FileGalatImportDiunduh);

        return $path;
    }

    /**
     * A new empty file in the system temporary folder, for a download that
     * deletes it once sent. tempnam() itself creates the file, so this is
     * the one file made (no second one with an extension added).
     */
    public static function fileSementaraBaru(string $awalan): string
    {
        $path = tempnam(sys_get_temp_dir(), $awalan);

        if ($path === false) {
            throw new RuntimeException('Tidak bisa membuat file sementara.');
        }

        return $path;
    }

    /**
     * Whether an import is being checked or saved now, which holds the
     * identity locks (KunciIdentitas) until it ends.
     */
    public function sedangMemproses(): bool
    {
        return ImportSiswa::query()
            ->whereIn('status', [StatusImportSiswa::Memeriksa->value, StatusImportSiswa::Menyimpan->value])
            ->whereNotNull('diproses_sejak')
            ->exists();
    }

    public static function pesanTerlaluBesar(): string
    {
        return sprintf('File terlalu besar: paling besar %d MB. Bagi menjadi beberapa file, paling banyak %d baris per file.', self::MAKS_UKURAN_KB / 1024, KolomImportSiswa::MAKS_BARIS);
    }

    /**
     * @return list<string>
     */
    private static function statusBelumBerakhir(): array
    {
        return array_values(array_map(
            fn (StatusImportSiswa $status): string => $status->value,
            array_filter(StatusImportSiswa::cases(), fn (StatusImportSiswa $status): bool => ! $status->selesai()),
        ));
    }

    /**
     * Move the import to the values of $isian only while its status is one
     * of $dari: a single conditional UPDATE, so of two processes moving it
     * at once (an operator, a job, the cleanup) exactly one wins and the
     * other sees false. The values go through the model's casts.
     *
     * @param  list<StatusImportSiswa>  $dari
     * @param  array<string, mixed>  $isian
     */
    private function ubahJikaMasih(ImportSiswa $import, array $dari, array $isian): bool
    {
        return ImportSiswa::query()
            ->whereKey($import->getKey())
            ->whereIn('status', array_map(fn (StatusImportSiswa $status): string => $status->value, $dari))
            ->update((new ImportSiswa)->forceFill($isian)->getAttributes()) === 1;
    }

    /**
     * End the import with $status while it is still in one of $dari (see
     * ubahJikaMasih()); $catat writes its log entry in the same
     * transaction. Only the process that ended it deletes its file.
     *
     * @param  list<StatusImportSiswa>  $dari
     * @param  (Closure(ImportSiswa): void)|null  $catat
     */
    private function akhiri(ImportSiswa $import, array $dari, StatusImportSiswa $status, ?string $pesan, ?Closure $catat = null): bool
    {
        $path = ImportSiswa::query()->whereKey($import->getKey())->value('path');

        $berakhir = DB::transaction(function () use ($import, $dari, $status, $pesan, $catat): bool {
            $diakhiri = $this->ubahJikaMasih($import, $dari, [
                'status' => $status,
                'pesan' => $pesan,
                'path' => null,
                'diproses_sejak' => null,
                'selesai_pada' => now(),
            ]);

            if ($diakhiri && $catat !== null) {
                $catat($import);
            }

            return $diakhiri;
        });

        if ($berakhir) {
            $this->hapusFile(is_string($path) ? $path : null);
        }

        $import->refresh();

        return $berakhir;
    }

    /**
     * Why the import cannot be checked or cancelled now.
     */
    private function ditolak(ImportSiswa $import): PelanggaranAturanKesiswaan
    {
        $status = ImportSiswa::query()->findOrFail($import->getKey(), ['id', 'status'])->status;

        return match (true) {
            $status->selesai() => PelanggaranAturanKesiswaan::importSudahBerakhir(),
            default => PelanggaranAturanKesiswaan::importSedangDiproses(),
        };
    }

    /**
     * The import as stored, with what a run reads.
     */
    private function muat(ImportSiswa $import): ImportSiswa
    {
        return ImportSiswa::query()->with(['tahunAjaran', 'user'])->findOrFail($import->getKey());
    }

    /**
     * A log entry about the import in the name of $pelaku: which file, for
     * which school year; never its content.
     */
    private function catat(ImportSiswa $import, User $pelaku, AksiKesiswaan $aksi): void
    {
        activity(SiswaService::LOG_NAME)
            ->causedBy($pelaku)
            ->performedOn($import)
            ->event($aksi->value)
            ->withProperties([
                'nama_file' => $import->nama_file,
                'tahun_ajaran' => $import->tahunAjaran->nama,
            ])
            ->log($aksi->label());
    }

    /**
     * Read the file and apply its rows in one transaction, in the name of
     * $pelaku. $simpan decides with the result: true commits, anything else
     * rolls back. Returns null when the file is gone (the import is then
     * gagal).
     *
     * @param  callable(HasilImportSiswa): (bool|void)  $simpan
     */
    private function jalankan(ImportSiswa $import, User $pelaku, callable $simpan): ?HasilImportSiswa
    {
        $path = (string) $import->path;

        if (! $this->fileAda($path)) {
            $this->akhiri($import, [StatusImportSiswa::Memeriksa, StatusImportSiswa::Menyimpan], StatusImportSiswa::Gagal, self::PESAN_FILE_TIDAK_ADA);

            return null;
        }

        $baca = $this->bacaFile->baca(Storage::disk(self::DISK)->path($path), $import->tahunAjaran);

        if ($baca->galatFile !== []) {
            return HasilImportSiswa::galatFile($baca->galatFile);
        }

        // A password or status change of an account in the file, committed
        // by someone else during the run, must not make it fail with
        // "Record has changed since last read" (IsolasiSnapshot).
        return IsolasiSnapshot::nonaktifkanSelama(fn (): HasilImportSiswa => UserService::tanpaMenyebutPemilikNik(function () use ($import, $pelaku, $baca, $simpan): HasilImportSiswa {
            DB::beginTransaction();

            try {
                $hasil = $this->proses->jalankan($pelaku, $import->tahunAjaran, $baca->baris);
                $tetap = $simpan($hasil) === true;
            } catch (Throwable $exception) {
                DB::rollBack();

                throw $exception;
            }

            $tetap ? DB::commit() : DB::rollBack();

            return $hasil;
        }));
    }

    /**
     * @return array{ringkasan: array<string, int>, galat: list<array{baris: int|null, kolom: string|null, pesan: string}>, pindah: list<array{baris: int, nis: string, nama: string, dari: string, ke: string}>, diproses_sejak: null}
     */
    private function hasil(HasilImportSiswa $hasil): array
    {
        return ['ringkasan' => $hasil->ringkasan, 'galat' => $hasil->galat, 'pindah' => $hasil->pindah, 'diproses_sejak' => null];
    }

    /**
     * An uploaded file directly in FOLDER (no other folder, no "..") with
     * the .xlsx extension, that exists on DISK.
     */
    private function fileAda(?string $path): bool
    {
        return is_string($path)
            && preg_match('#^'.self::FOLDER.'/[A-Za-z0-9_-]+\.xlsx$#', $path) === 1
            && Storage::disk(self::DISK)->exists($path);
    }

    /**
     * Deletes only a file directly in FOLDER.
     */
    private function hapusFile(?string $path): void
    {
        if ($this->fileAda($path)) {
            Storage::disk(self::DISK)->delete((string) $path);
        }
    }
}
