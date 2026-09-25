<?php

return [
    // Deterministic resolution and alias learning are always active. This opt-in
    // permits at most one small semantic comparison per document/content hash.
    'semantic_matching' => env('KPI_SEMANTIC_MATCHING_ENABLED', false),
];
