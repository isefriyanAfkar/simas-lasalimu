<?php

namespace App\Filament\Support;

use Illuminate\Validation\ValidationException;

/**
 * Service validation errors use plain keys (nama, tanggal_mulai); a Filament
 * form expects them under its state path: 'data' on a Create or Edit page,
 * 'mountedActions.{n}.data' in an action modal.
 */
final class GalatValidasi
{
    public static function prefix(ValidationException $exception, string $statePath): ValidationException
    {
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            $errors["{$statePath}.{$field}"] = $messages;
        }

        return ValidationException::withMessages($errors);
    }
}
