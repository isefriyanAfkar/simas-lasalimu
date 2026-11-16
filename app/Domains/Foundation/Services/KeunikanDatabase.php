<?php

namespace App\Domains\Foundation\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Last line for unique fields. A Service checks uniqueness with a validation
 * rule inside a locked transaction; if another save still slips in between
 * (or the lock cannot cover it), the database's unique index rejects the
 * write. This turns that rejection into a field error instead of a server
 * error.
 */
final class KeunikanDatabase
{
    /**
     * Run $operasi; when it breaks one of the named unique indexes, throw a
     * ValidationException on that index's field. Any other unique violation
     * is rethrown unchanged.
     *
     * @template T
     *
     * @param  array<string, array{string, string}>  $indeks  index name => [field, message]
     * @param  callable(): T  $operasi
     * @return T
     *
     * @throws ValidationException
     */
    public static function jaga(array $indeks, callable $operasi): mixed
    {
        try {
            return $operasi();
        } catch (UniqueConstraintViolationException $exception) {
            foreach ($indeks as $namaIndeks => [$field, $pesan]) {
                if (str_contains($exception->getMessage(), "'{$namaIndeks}'")) {
                    throw ValidationException::withMessages([$field => $pesan]);
                }
            }

            throw $exception;
        }
    }
}
