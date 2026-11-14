<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\PelanggaranAturanPengguna;
use App\Domains\Foundation\Services\UserService;
use App\Filament\Resources\Users\UserResource;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $pelaku */
        $pelaku = Filament::auth()->user();

        try {
            return app(UserService::class)->create($pelaku, $data);
        } catch (PelanggaranAturanPengguna $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            $this->halt();
        }
    }
}
