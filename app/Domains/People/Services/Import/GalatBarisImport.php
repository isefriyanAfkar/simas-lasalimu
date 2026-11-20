<?php

namespace App\Domains\People\Services\Import;

use RuntimeException;

/**
 * Thrown inside one row of the import (ProsesImportSiswa) to roll that row
 * back to its savepoint, carrying its errors per template column.
 */
final class GalatBarisImport extends RuntimeException
{
    /**
     * @param  list<array{string|null, string}>  $galat  [column key or null, message]
     */
    public function __construct(public readonly array $galat)
    {
        parent::__construct('Baris import bergalat.');
    }
}
