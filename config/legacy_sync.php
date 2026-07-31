<?php

return [
    // Shared only with the office-side agent. Leave empty to disable this endpoint.
    'shared_secret' => env('LEGACY_POS_SYNC_SECRET'),
    'max_clock_skew_seconds' => 300,
];
