<?php

return [
    'enabled' => (bool) env('DOCINTEL_INTELLIGENCE_V2', false),
    'workspaces' => [],
    'brief' => (bool) env('DOCINTEL_V2_BRIEF', true),
    'charts' => (bool) env('DOCINTEL_V2_CHARTS', true),
];
