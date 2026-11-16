<?php

namespace App\Domains\Academic\Models;

use App\Domains\Academic\Enums\JenisSemester;
use App\Domains\Academic\Policies\TahunAjaranPolicy;
use Database\Factories\TahunAjaranFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Written only through TahunAjaranService. Read the active one through
 * PeriodeAkademik, never by querying status_aktif directly.
 *
 * penanda_aktif is a database-only generated column (1 while active, NULL
 * otherwise) whose unique index allows one active row. It is hidden and its
 * in-memory value goes stale after an update; never read it.
 *
 * @property string $nama
 * @property Carbon $tanggal_mulai
 * @property Carbon $tanggal_selesai
 * @property bool $status_aktif
 */
#[Table('tahun_ajaran')]
#[Fillable(['nama', 'tanggal_mulai', 'tanggal_selesai', 'status_aktif'])]
#[Hidden(['penanda_aktif'])]
#[UseFactory(TahunAjaranFactory::class)]
#[UsePolicy(TahunAjaranPolicy::class)]
class TahunAjaran extends Model
{
    /** @use HasFactory<TahunAjaranFactory> */
    use HasFactory, LogsActivity;

    /**
     * @return HasMany<Semester, $this>
     */
    public function semester(): HasMany
    {
        return $this->hasMany(Semester::class);
    }

    /**
     * @return HasMany<Rombel, $this>
     */
    public function rombel(): HasMany
    {
        return $this->hasMany(Rombel::class);
    }

    /**
     * @return HasOne<Semester, $this>
     */
    public function semesterGanjil(): HasOne
    {
        return $this->hasOne(Semester::class)->where('jenis', JenisSemester::Ganjil->value);
    }

    /**
     * @return HasOne<Semester, $this>
     */
    public function semesterGenap(): HasOne
    {
        return $this->hasOne(Semester::class)->where('jenis', JenisSemester::Genap->value);
    }

    /**
     * Plain create/update/delete is logged by the trait. Activation is logged
     * explicitly by TahunAjaranService, so status_aktif is left out here.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('akademik')
            ->logOnly(['nama', 'tanggal_mulai', 'tanggal_selesai'])
            ->useAttributeRawValues(['tanggal_mulai', 'tanggal_selesai'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName): string => match ($eventName) {
                'created' => 'Tahun ajaran dibuat',
                'updated' => 'Tahun ajaran diubah',
                'deleted' => 'Tahun ajaran dihapus',
                default => "Tahun ajaran {$eventName}",
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'status_aktif' => 'boolean',
        ];
    }
}
