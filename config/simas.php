<?php

return [

    /*
    |--------------------------------------------------------------------------
    | NIK HMAC key
    |--------------------------------------------------------------------------
    |
    | Secret key for nik_hash, the HMAC-SHA256 of a NIK that makes encrypted
    | NIKs searchable and unique. It is separate from APP_KEY. Use at least
    | 32 bytes, written like APP_KEY ("base64:..."). Back it up apart from the
    | database backup: once it is lost, stored NIKs can no longer be searched
    | or checked for duplicates.
    |
    */

    'nik_hmac_key' => env('NIK_HMAC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Temporary files
    |--------------------------------------------------------------------------
    |
    | Folder for the files made for a download and deleted once sent: the
    | import error file (which can hold NIKs), the import template, the
    | sample import. It is on the private disk of the application, never the
    | system temporary folder, which other sites share on shared hosting.
    | Created when first needed; simas:bersihkan-import deletes what is left
    | after a day. Parallel tests give every process its own folder
    | (AppServiceProvider).
    |
    */

    'folder_sementara' => storage_path('app/private/tmp'),

];
