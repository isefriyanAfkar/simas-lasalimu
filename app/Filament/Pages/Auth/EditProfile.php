<?php

namespace App\Filament\Pages\Auth;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * Users may change only their own name and password. Username and email are
 * not on the form, and UserService ignores them even if they are submitted.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
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
        return app(UserService::class)->updateOwnProfile($record, [
            'name' => $data['name'],
            'password' => $data['password'] ?? null,
        ]);
    }
}
