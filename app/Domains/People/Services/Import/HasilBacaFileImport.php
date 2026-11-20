<?php

namespace App\Domains\People\Services\Import;

/**
 * What BacaFileImportSiswa read: either errors about the file itself (it
 * cannot be read, lacks columns, holds too many rows), and then no rows, or
 * the data rows, each with its own errors.
 */
final class HasilBacaFileImport
{
    /**
     * @param  list<string>  $galatFile
     * @param  list<BarisImportSiswa>  $baris
     */
    public function __construct(
        public readonly array $galatFile,
        public readonly array $baris,
    ) {}
}
