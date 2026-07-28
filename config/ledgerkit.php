<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | Named connection the ledger tables live on. Null uses the host
    | application's default connection. Only pgsql and mysql are supported.
    |
    */

    'connection' => null,

    'snapshot' => [

        /*
        | Postings younger than this are excluded from a snapshot boundary.
        | Sequence ids commit out of order, so a boundary taken at the newest
        | id can permanently skip an older id that is still in flight. Must
        | exceed the longest write transaction against the ledger tables.
        */

        'safety_window_seconds' => 300,

        /*
        | Skip building when fewer postings sit past the last boundary.
        */

        'min_tail' => 1000,

    ],

];
