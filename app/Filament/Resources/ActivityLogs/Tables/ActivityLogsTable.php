<?php

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\MataPelajaran;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\Semester;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\AktivitasAkademik;
use App\Domains\Foundation\Enums\AksiPengguna;
use App\Domains\Foundation\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

class ActivityLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'causer',
                'subject' => function (Relation $relation): void {
                    if ($relation instanceof MorphTo) {
                        $relation->morphWith([Semester::class => ['tahunAjaran'], Rombel::class => ['tahunAjaran']]);
                    }
                },
            ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label('Pelaku')
                    ->default('Sistem'),
                TextColumn::make('event')
                    ->label('Aksi')
                    ->formatStateUsing(fn (?string $state, Activity $record): string => AksiPengguna::tryFrom((string) $state)?->label() ?? $record->description),
                TextColumn::make('objek')
                    ->label('Objek')
                    ->state(fn (Activity $record): ?string => self::describeSubject($record)),
                TextColumn::make('rincian')
                    ->label('Rincian perubahan')
                    ->state(fn (Activity $record): ?string => self::describeChanges($record))
                    ->wrap(),
            ])
            ->filters([
                Filter::make('waktu')
                    ->schema([
                        DatePicker::make('dari')->label('Dari tanggal'),
                        DatePicker::make('sampai')->label('Sampai tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['dari'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['sampai'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['dari'] ?? null) {
                            $indicators[] = Indicator::make('Dari '.Carbon::parse($data['dari'])->translatedFormat('d M Y'))
                                ->removeField('dari');
                        }

                        if ($data['sampai'] ?? null) {
                            $indicators[] = Indicator::make('Sampai '.Carbon::parse($data['sampai'])->translatedFormat('d M Y'))
                                ->removeField('sampai');
                        }

                        return $indicators;
                    }),
                SelectFilter::make('pelaku')
                    ->label('Pelaku')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $userId): Builder => $query
                            ->where('causer_type', (new User)->getMorphClass())
                            ->where('causer_id', $userId),
                    )),
            ]);
    }

    private static function describeSubject(Activity $activity): ?string
    {
        $subject = $activity->subject;

        if ($subject instanceof User) {
            return $subject->username;
        }

        if ($subject instanceof TahunAjaran || $subject instanceof Semester || $subject instanceof MataPelajaran || $subject instanceof Rombel) {
            return AktivitasAkademik::label($subject);
        }

        if ($activity->log_name === 'pengaturan') {
            return 'Pengaturan sekolah';
        }

        if ($activity->subject_type === null) {
            return null;
        }

        $label = $activity->getProperty('label');

        if (is_string($label)) {
            return $label;
        }

        return class_basename($activity->subject_type).' #'.$activity->subject_id;
    }

    /**
     * "field: old → new" per changed attribute, or "field: value" when there is
     * no old value, followed by "menggantikan <label>" for an activation that
     * replaced another period. A deletion has only old values; they are shown
     * as "field: value".
     */
    private static function describeChanges(Activity $activity): ?string
    {
        $new = $activity->attribute_changes['attributes'] ?? [];
        $old = $activity->attribute_changes['old'] ?? [];
        $digantikan = $activity->getProperty('menggantikan.label');

        if ($new === [] && $old !== []) {
            [$new, $old] = [$old, []];
        }

        $parts = [];

        foreach ($new as $attribute => $value) {
            $parts[] = array_key_exists($attribute, $old)
                ? sprintf('%s: %s → %s', $attribute, self::formatValue($attribute, $old[$attribute]), self::formatValue($attribute, $value))
                : sprintf('%s: %s', $attribute, self::formatValue($attribute, $value));
        }

        if (is_string($digantikan)) {
            $parts[] = "menggantikan {$digantikan}";
        }

        return $parts === [] ? null : implode('; ', $parts);
    }

    private static function formatValue(string $attribute, mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            $attribute === 'status_aktif' => $value ? 'aktif' : 'nonaktif',
            $attribute === 'tingkat' && is_numeric($value) => Tingkat::tryFrom((int) $value)?->label() ?? (string) $value,
            is_bool($value) => $value ? 'ya' : 'tidak',
            default => (string) $value,
        };
    }
}
