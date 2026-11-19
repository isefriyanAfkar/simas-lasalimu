<?php

namespace App\Domains\People\Models;

use App\Domains\Foundation\Models\User;
use App\Domains\People\Policies\WaliMuridPolicy;
use Database\Factories\WaliMuridFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A parent or guardian and the account they belong to. The name and biodata
 * live on the account (users), which stays inactive until the parent portal
 * exists. One parent may be linked to several students (siblings) through
 * siswa_wali. Written only through WaliMuridService and SiswaWaliService.
 * Deleted, with the account, only while no student is linked.
 *
 * @property int $user_id
 * @property string|null $pekerjaan
 * @property bool $meninggal
 * @property User $user
 */
#[Table('wali_murid')]
#[Fillable(['user_id', 'pekerjaan', 'meninggal'])]
#[UseFactory(WaliMuridFactory::class)]
#[UsePolicy(WaliMuridPolicy::class)]
class WaliMurid extends Model
{
    /** @use HasFactory<WaliMuridFactory> */
    use HasFactory, LogsActivity;

    /**
     * Deleting is logged explicitly by WaliMuridService (wali_dihapus).
     *
     * @var list<string>
     */
    protected static array $recordEvents = ['created', 'updated'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SiswaWali, $this>
     */
    public function siswaWali(): HasMany
    {
        return $this->hasMany(SiswaWali::class);
    }

    /**
     * Fields logged by the trait. meninggal is logged explicitly by
     * WaliMuridService.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('kesiswaan')
            ->logOnly(['user_id', 'pekerjaan'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName): string => match ($eventName) {
                'created' => 'Data wali murid dibuat',
                'updated' => 'Data wali murid diubah',
                'deleted' => 'Data wali murid dihapus',
                default => "Data wali murid {$eventName}",
            });
    }

    /**
     * Every log entry about this parent keeps the name in properties.label,
     * so the Activity Log can still name them after they are deleted.
     */
    public function beforeActivityLogged(Model $activity, string $eventName): void
    {
        $activity->setAttribute('properties', collect($activity->getAttribute('properties'))->put('label', $this->user->name));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meninggal' => 'boolean',
        ];
    }
}
