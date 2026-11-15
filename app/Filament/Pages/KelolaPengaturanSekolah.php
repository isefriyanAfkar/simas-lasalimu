<?php

namespace App\Filament\Pages;

use App\Domains\Foundation\Enums\Jenjang;
use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PengaturanSekolahService;
use App\Domains\Foundation\Settings\PengaturanSekolah;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Facades\FilamentView;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * School settings, super_admin only. Saving goes through
 * PengaturanSekolahService instead of the plugin's direct fill()->save().
 * Excluded from Shield in config/filament-shield.php, so no grantable
 * permission is generated for it.
 */
class KelolaPengaturanSekolah extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string $settings = PengaturanSekolah::class;

    protected static ?string $navigationLabel = 'Pengaturan Sekolah';

    protected static ?string $title = 'Pengaturan Sekolah';

    protected static ?string $slug = 'pengaturan-sekolah';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nama_sekolah')
                    ->label('Nama sekolah')
                    ->required()
                    ->maxLength(255),
                TextInput::make('npsn')
                    ->label('NPSN')
                    ->regex('/^\d{8}$/')
                    ->validationMessages(['regex' => 'NPSN harus terdiri dari tepat 8 digit angka.'])
                    ->length(8),
                Select::make('jenjang')
                    ->label('Jenjang')
                    ->options(Jenjang::options()),
                TextInput::make('telepon')
                    ->label('Telepon')
                    ->tel()
                    ->maxLength(30),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(255),
                Textarea::make('alamat')
                    ->label('Alamat')
                    ->maxLength(1000)
                    ->columnSpanFull(),
                FileUpload::make('logo')
                    ->label('Logo')
                    ->image()
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    ->maxSize(1024)
                    ->disk(PengaturanSekolahService::LOGO_DISK)
                    ->directory(PengaturanSekolahService::LOGO_DIRECTORY)
                    ->visibility('public')
                    ->helperText('PNG, JPG, atau WebP, maksimal 1 MB.'),
            ]);
    }

    /**
     * Same flow as the parent, but the write goes through the Service so the
     * rules, the activity log and the old-logo cleanup always apply.
     */
    public function save(): void
    {
        if (! $this->canEdit()) {
            return;
        }

        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        $data = $this->form->getState();

        try {
            app(PengaturanSekolahService::class)->simpan($pelaku, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
                    ->all(),
            );
        }

        $this->rememberData();
        $this->getSavedNotification()?->send();

        if ($redirectUrl = $this->getRedirectUrl()) {
            $this->redirect($redirectUrl, navigate: FilamentView::hasSpaMode($redirectUrl));
        }
    }

    /**
     * Reload after saving, so the panel brand (name and logo) shows the new values.
     */
    #[Unexposed]
    public function getRedirectUrl(): ?string
    {
        return static::getUrl();
    }
}
