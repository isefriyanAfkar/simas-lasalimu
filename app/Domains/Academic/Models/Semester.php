<?php

namespace App\Domains\Academic\Models;

use App\Domains\Academic\Enums\JenisSemester;
use App\Domains\Academic\Policies\SemesterPolicy;
use Database\Factories\SemesterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Created and deleted only together with its TahunAjaran by
 * TahunAjaranService; only its dates change, through SemesterService.
 *
 * @property JenisSemester $jenis
 * @property Carbon $tanggal_mulai
 * @property Carbon $tanggal_selesai
 * @property bool $status_aktif
 * @property TahunAjaran $tahunAjaran
 */
#[Table('semester')]
#[Fillable(['tahun_ajaran_id', 'jenis', 'tanggal_mulai', 'tanggal_selesai', 'status_aktif'])]
#[UseFactory(SemesterFactory::class)]
#[UsePolicy(SemesterPolicy::class)]
class Semester extends Model
{
    /** @use HasFactory<SemesterFactory> */
    use HasFactory, LogsActivity;

    /**
     * @return BelongsTo<TahunAjaran, $this>
     */
    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    /**
     * Plain create/update/delete is logged by the trait. Activation is logged
     * explicitly by SemesterService, so status_aktif is left out here.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('akademik')
            ->logOnly(['jenis', 'tanggal_mulai', 'tanggal_selesai'])
            ->useAttributeRawValues(['tanggal_mulai', 'tanggal_selesai'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName): string => match ($eventName) {
                'created' => 'Semester dibuat',
                'updated' => 'Semester diubah',
                'deleted' => 'Semester dihapus',
                default => "Semester {$eventName}",
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jenis' => JenisSemester::class,
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'status_aktif' => 'boolean',
        ];
    }
}
