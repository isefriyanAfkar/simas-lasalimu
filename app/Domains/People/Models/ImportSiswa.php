<?php

namespace App\Domains\People\Models;

use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Foundation\Models\User;
use App\Domains\People\Enums\StatusImportSiswa;
use Carbon\CarbonInterface;
use Database\Factories\ImportSiswaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student import from an Excel file. Written only through
 * ImportSiswaService.
 *
 * - ringkasan: the counts of the last check, see ImportSiswaService::RINGKASAN
 * - galat: list of {baris, kolom, pesan}; baris is the Excel row (null for
 *   the file as a whole), kolom a key of KolomImportSiswa::KOLOM or null for
 *   the row. Never a NIK, never the name of whoever holds one
 * - pindah: the students that change rombel, list of {baris, nis, nama,
 *   dari, ke}
 *
 * @property int $user_id
 * @property int $tahun_ajaran_id
 * @property string $nama_file
 * @property string|null $path
 * @property StatusImportSiswa $status
 * @property array<string, int>|null $ringkasan
 * @property list<array{baris: int|null, kolom: string|null, pesan: string}>|null $galat
 * @property list<array{baris: int, nis: string, nama: string, dari: string, ke: string}>|null $pindah
 * @property string|null $pesan
 * @property CarbonInterface|null $diproses_sejak
 * @property CarbonInterface|null $selesai_pada
 * @property User $user
 * @property TahunAjaran $tahunAjaran
 */
#[Table('import_siswa')]
#[Fillable([
    'user_id', 'tahun_ajaran_id', 'nama_file', 'path', 'status', 'ringkasan', 'galat', 'pindah', 'pesan',
    'diproses_sejak', 'selesai_pada',
])]
#[UseFactory(ImportSiswaFactory::class)]
class ImportSiswa extends Model
{
    /** @use HasFactory<ImportSiswaFactory> */
    use HasFactory;

    /**
     * The operator who uploaded the file; every change of the import is
     * made in their name.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<TahunAjaran, $this>
     */
    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StatusImportSiswa::class,
            'ringkasan' => 'array',
            'galat' => 'array',
            'pindah' => 'array',
            'diproses_sejak' => 'datetime',
            'selesai_pada' => 'datetime',
        ];
    }
}
