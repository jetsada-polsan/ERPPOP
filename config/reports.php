<?php

return [
    // Emergency stop for Phase 2 report queries; authorization fixes remain enforced.
    'phase_two_enabled' => env('REPORTS_PHASE_TWO_ENABLED', true),
];
