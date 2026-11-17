<?php

namespace App\Domains\People\Models;

use App\Domains\People\Policies\StatusKepegawaianPolicy;
use Database\Factories\StatusKepegawaianFactory;
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
 * Employment status of a staff member (PNS, PPPK, GTT, ...). Written only
 * through StatusKepegawaianService. Never deleted: a status that is no longer
 * used is deactivated, and an inactive status is not offered for new staff.
 *
 * @property string $nama
 * @property int|null $urutan
 * @property bool $status_aktif
 */
#[Table('status_kepegawaian')]
#[Fillable(['nama', 'urutan', 'status_aktif'])]
#[UseFactory(StatusKepegawaianFactory::class)]
#[UsePolicy(StatusKepegawaianPolicy::class)]
class StatusKepegawaian extends Model
{
    /** @use HasFactory<StatusKepegawaianFactory> */
    use HasFactory, LogsActivity;

    /**
     * Display order: by urutan (empty last), then by nama.
     *
     * @param  Builder<StatusKepegawaian>  $query
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
     * are logged explicitly by StatusKepegawaianService, so status_aktif is
     * left out here.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('kepegawaian')
            ->logOnly(['nama', 'urutan'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName): string => match ($eventName) {
                'created' => 'Status kepegawaian dibuat',
                'updated' => 'Status kepegawaian diubah',
                'deleted' => 'Status kepegawaian dihapus',
                default => "Status kepegawaian {$eventName}",
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
