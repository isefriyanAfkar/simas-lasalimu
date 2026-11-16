<?php

namespace App\Domains\Academic\Models;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Policies\RombelPolicy;
use Database\Factories\RombelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A study group (rombongan belajar) in one school year. Written only through
 * RombelService.
 *
 * Its name is never typed or stored: it is built from tingkat and nomor
 * ("X-1"). Every module (schedules, journals, attendance) reads it through
 * $rombel->nama, or Rombel::namaDari() when it has no model.
 *
 * @property int $tahun_ajaran_id
 * @property Tingkat $tingkat
 * @property int $nomor
 * @property int|null $kapasitas
 * @property-read string $nama
 * @property TahunAjaran $tahunAjaran
 */
#[Table('rombel')]
#[Fillable(['tahun_ajaran_id', 'tingkat', 'nomor', 'kapasitas'])]
#[UseFactory(RombelFactory::class)]
#[UsePolicy(RombelPolicy::class)]
class Rombel extends Model
{
    /** @use HasFactory<RombelFactory> */
    use HasFactory, LogsActivity;

    /**
     * The one standard way to name a study group: Roman grade, a dash, the
     * number. "X-1", "XI-2".
     */
    public static function namaDari(Tingkat $tingkat, int $nomor): string
    {
        return "{$tingkat->label()}-{$nomor}";
    }

    /**
     * @return BelongsTo<TahunAjaran, $this>
     */
    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    /**
     * Display order: by tingkat, then by nomor.
     *
     * @param  Builder<Rombel>  $query
     */
    #[Scope]
    protected function urutanTampilan(Builder $query): void
    {
        $query->orderBy('tingkat')->orderBy('nomor');
    }

    /**
     * @return Attribute<string, never>
     */
    protected function nama(): Attribute
    {
        return Attribute::get(fn (): string => self::namaDari($this->tingkat, $this->nomor));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('akademik')
            ->logOnly(['tahun_ajaran_id', 'tingkat', 'nomor', 'kapasitas'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName): string => match ($eventName) {
                'created' => 'Rombel dibuat',
                'updated' => 'Rombel diubah',
                'deleted' => 'Rombel dihapus',
                default => "Rombel {$eventName}",
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tingkat' => Tingkat::class,
            'nomor' => 'integer',
            'kapasitas' => 'integer',
        ];
    }
}
