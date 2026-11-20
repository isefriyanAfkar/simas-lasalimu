<?php

namespace App\Domains\People\Services\Import;

/**
 * One data row of the import file after reading: the cell values in the
 * form the services expect (null for an empty cell, which never changes
 * stored data; the rombel as its id), and the reading errors per column.
 * An error never contains a cell's value.
 */
final class BarisImportSiswa
{
    /**
     * @param  int  $nomor  the row number in Excel (the header is row 1)
     * @param  array<string, mixed>  $nilai  column key (KolomImportSiswa) => value
     * @param  array<string, string>  $galat  column key => message
     */
    public function __construct(
        public readonly int $nomor,
        public readonly array $nilai,
        public readonly array $galat,
    ) {}
}
