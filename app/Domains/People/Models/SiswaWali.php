<?php

namespace App\Domains\People\Models;

use App\Domains\People\Enums\HubunganWali;
use Database\Factories\SiswaWaliFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One parent or guardian of one student. Per student: each guardian once, at
 * most one ayah and one ibu, at most one utama (the main contact). Written
 * only through SiswaWaliService, which logs every change explicitly with the
 * student as subject.
 *
 * @property int $siswa_id
 * @property int $wali_murid_id
 * @property HubunganWali $hubungan
 * @property bool $utama
 * @property Siswa $siswa
 * @property WaliMurid $waliMurid
 */
#[Table('siswa_wali')]
#[Fillable(['siswa_id', 'wali_murid_id', 'hubungan', 'utama'])]
#[UseFactory(SiswaWaliFactory::class)]
class SiswaWali extends Model
{
    /** @use HasFactory<SiswaWaliFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Siswa, $this>
     */
    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class);
    }

    /**
     * @return BelongsTo<WaliMurid, $this>
     */
    public function waliMurid(): BelongsTo
    {
        return $this->belongsTo(WaliMurid::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'siswa_id' => 'integer',
            'wali_murid_id' => 'integer',
            'hubungan' => HubunganWali::class,
            'utama' => 'boolean',
        ];
    }
}
