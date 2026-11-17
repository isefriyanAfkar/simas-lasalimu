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

];
