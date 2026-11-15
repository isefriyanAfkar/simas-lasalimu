<?php

namespace App\Console\Commands;

use App\Domains\Foundation\Services\UserService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates an active super_admin from the terminal, e.g. on a new server.
 * All answers are validated by UserService with the application's rules;
 * on any error the command prints the messages and exits with code 1.
 */
#[Signature('simas:buat-super-admin')]
#[Description('Buat super admin baru (aktif, role super_admin) dari terminal')]
class BuatSuperAdmin extends Command
{
    public function handle(UserService $userService): int
    {
        if (! $this->input->isInteractive()) {
            $this->components->error('Perintah ini harus dijalankan secara interaktif dari terminal, tanpa --no-interaction.');

            return self::FAILURE;
        }

        $data = [
            'name' => text(label: 'Nama', required: true),
            'username' => text(label: 'Username', required: true),
            'password' => password(label: 'Kata sandi', required: true, hint: 'Minimal 8 karakter.'),
            'password_confirmation' => password(label: 'Ulangi kata sandi', required: true),
        ];

        try {
            $user = $userService->createSuperAdminFromSystem($data);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $this->components->info("Super admin {$user->username} dibuat.");

        return self::SUCCESS;
    }
}
