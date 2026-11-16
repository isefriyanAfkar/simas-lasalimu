<?php

namespace App\Domains\Academic\Models;

use App\Domains\Academic\Policies\MataPelajaranPolicy;
use Database\Factories\MataPelajaranFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Written only through MataPelajaranService. Never deleted: a subject that is
 * no longer taught is deactivated, and can be activated again.
 *
 * @property string $kode
 * @property string $nama
 * @property int|null $urutan
 * @property bool $status_aktif
 */
#[Table('mata_pelajaran')]
#[Fillable(['kode', 'nama', 'urutan', 'status_aktif'])]
#[UseFactory(MataPelajaranFactory::class)]
#[UsePolicy(MataPelajaranPolicy::class)]
class MataPelajaran extends Model
{
    /** @use HasFactory<MataPelajaranFactory> */
    use HasFactory, LogsActivity;

    /**
     * Display order: by urutan (empty last), then by nama.
     *
     * @param  Builder<MataPelajaran>  $query
     */
    #[Scope]
    protected function urutanTampilan(Builder $query): void
    {
        $query
            ->orderByRaw('urutan is null')
            ->orderBy('urutan')
            ->orderBy('nama');
    }

    /**
     * Plain create/update is logged by the trait. Deactivating and activating
     * are logged explicitly by MataPelajaranService, so status_aktif is left
     * out here.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('akademik')
            ->logOnly(['kode', 'nama', 'urutan'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName): string => match ($eventName) {
                'created' => 'Mata pelajaran dibuat',
                'updated' => 'Mata pelajaran diubah',
                'deleted' => 'Mata pelajaran dihapus',
                default => "Mata pelajaran {$eventName}",
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
            'status_aktif' => 'boolean',
        ];
    }
}
