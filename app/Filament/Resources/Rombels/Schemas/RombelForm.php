<?php

namespace App\Filament\Resources\Rombels\Schemas;

use App\Domains\Academic\Enums\Tingkat;
use App\Domains\Academic\Models\Rombel;
use App\Domains\Academic\Models\TahunAjaran;
use App\Domains\Academic\Services\PeriodeAkademik;
use App\Domains\Academic\Services\RombelService;
use App\Domains\Foundation\Settings\PengaturanSekolah;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Field rules here are only for convenience; RombelService decides. The name
 * is not a field: it is built from tingkat and nomor.
 */
class RombelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('tahun_ajaran_id')
                    ->label('Tahun ajaran')
                    ->options(fn (): array => TahunAjaran::query()->orderByDesc('tanggal_mulai')->pluck('nama', 'id')->all())
                    ->default(fn (): ?int => app(PeriodeAkademik::class)->tahunAjaranAktif()?->id)
                    ->required(),
                Select::make('tingkat')
                    ->label('Tingkat')
                    ->options(fn (?Rombel $record): array => self::tingkatOptions($record))
                    ->helperText(fn (): ?string => app(PengaturanSekolah::class)->jenjang === null ? RombelService::PESAN_JENJANG_KOSONG : null)
                    // Not required while the jenjang is empty: there is
                    // nothing to choose, and RombelService then answers with
                    // its own message instead of a generic "required" one.
                    ->required(fn (): bool => app(PengaturanSekolah::class)->jenjang !== null),
                TextInput::make('nomor')
                    ->label('Nomor')
                    ->helperText('Nama rombel dibentuk dari tingkat dan nomor, misalnya X-1.')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(99)
                    ->required(),
                TextInput::make('kapasitas')
                    ->label('Kapasitas')
                    ->helperText('Boleh kosong.')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(99),
            ]);
    }

    /**
     * The levels of the school's jenjang, plus the record's own level, so an
     * old rombel stays editable after the jenjang changes.
     *
     * @return array<int, string>
     */
    private static function tingkatOptions(?Rombel $record): array
    {
        $options = Tingkat::optionsUntukJenjang(app(PengaturanSekolah::class)->jenjang);

        if ($record !== null) {
            $options[$record->tingkat->value] = $record->tingkat->label();
            ksort($options);
        }

        return $options;
    }
}
