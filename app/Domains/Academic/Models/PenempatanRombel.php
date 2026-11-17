<?php

namespace App\Domains\Academic\Models;

use App\Domains\People\Models\Siswa;
use Database\Factories\PenempatanRombelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's rombel in one school year. tahun_ajaran_id always equals the
 * rombel's school year (enforced by a foreign key on both columns). Written
 * only through PenempatanRombelService, which logs every change explicitly
 * with the student as subject. A student's rombel in the active school year
 * is read through RombelSiswa.
 *
 * @property int $siswa_id
 * @property int $rombel_id
 * @property int $tahun_ajaran_id
 * @property Siswa $siswa
 * @property Rombel $rombel
 * @property TahunAjaran $tahunAjaran
 */
#[Table('penempatan_rombel')]
#[Fillable(['siswa_id', 'rombel_id', 'tahun_ajaran_id'])]
#[UseFactory(PenempatanRombelFactory::class)]
class PenempatanRombel extends Model
{
    /** @use HasFactory<PenempatanRombelFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Siswa, $this>
     */
    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class);
    }

    /**
     * @return BelongsTo<Rombel, $this>
     */
    public function rombel(): BelongsTo
    {
        return $this->belongsTo(Rombel::class);
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
            'siswa_id' => 'integer',
            'rombel_id' => 'integer',
            'tahun_ajaran_id' => 'integer',
        ];
    }
}
