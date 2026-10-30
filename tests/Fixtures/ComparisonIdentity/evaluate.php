<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/run.php';
$report = evaluateComparisonIdentity();
file_put_contents(__DIR__.'/results.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($report['counts'], JSON_PRETTY_PRINT)."\n";
