<?php

namespace App\Filament\Pages\Auth;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Support\GalatValidasi;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Users may change only their own name, password, phone number, address and
 * photo. Username and email are not on the form; NIK (masked), gender, place
 * and date of birth and religion are shown read-only. UserService ignores
 * every other key even if it is submitted.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                TextInput::make('no_hp')
                    ->label('Nomor HP')
                    ->helperText('Boleh ditulis 08…, 62…, atau +62…. Disimpan dalam format 62.')
                    ->maxLength(20),
                Textarea::make('alamat')
                    ->label('Alamat')
                    ->maxLength(1000),
                UserForm::fotoField(),
                Section::make('Biodata')
                    ->description('Hubungi admin sekolah untuk mengubah data ini.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('nik_tersamar')
                            ->label('NIK')
                            ->state(fn (): ?string => $this->user()->nikTersamar())
                            ->placeholder('—')
                            ->helperText(UserForm::KETERANGAN_NIK),
                        TextEntry::make('jenis_kelamin_tampil')
                            ->label('Jenis kelamin')
                            ->state(fn (): ?string => $this->user()->jenis_kelamin?->label())
                            ->placeholder('—'),
                        TextEntry::make('tempat_tanggal_lahir')
                            ->label('Tempat, tanggal lahir')
                            ->state(fn (): ?string => $this->tempatTanggalLahir())
                            ->placeholder('—'),
                        TextEntry::make('agama_tampil')
                            ->label('Agama')
                            ->state(fn (): ?string => $this->user()->agama?->value)
                            ->placeholder('—'),
                    ]),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    /**
     * The parent also shows this field when the email changes; there is no
     * email field here, so it depends on the new password alone.
     */
    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()
            ->visible(fn (Get $get): bool => filled($get('password')));
    }

    /**
     * @param  User  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        try {
            return app(UserService::class)->updateOwnProfile($record, [
                'name' => $data['name'],
                'password' => $data['password'] ?? null,
                'no_hp' => $data['no_hp'] ?? null,
                'alamat' => $data['alamat'] ?? null,
                'foto' => $data['foto'] ?? null,
            ]);
        } catch (ValidationException $exception) {
            throw GalatValidasi::prefix($exception, 'data');
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function tempatTanggalLahir(): ?string
    {
        $user = $this->user();
        $parts = array_filter([$user->tempat_lahir, $user->tanggal_lahir?->translatedFormat('j F Y')]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}
