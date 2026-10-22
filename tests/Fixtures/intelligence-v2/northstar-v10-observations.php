<?php

/** Deterministic, hand-cited V10 input. This is not a recorded model response. */
$kpis = [];
$financial = [
    ['Revenue', 'revenue', 'E029', 'KES million', 'amount', 'total', ['42.0', '46.0', '51.0']],
    ['Operating costs', 'operating costs', 'E030', 'KES million', 'amount', 'total', ['33.0', '36.0', '41.0']],
    ['Operating profit', 'operating profit', 'E031', 'KES million', 'amount', 'total', ['9.0', '10.0', '10.0']],
    ['Operating margin', 'operating margin', 'E032', '%', 'percentage', null, ['21.4%', '21.7%', '19.6%']],
];
foreach ($financial as [$label, $concept, $row, $unit, $type, $aggregation, $values]) {
    foreach ($values as $index => $value) {
        $period = 'Q'.($index + 1).' 2026';
        $kpis[] = [
            'label' => $label, 'value' => $value, 'unit' => $unit,
            'trend' => null, 'trendValue' => null,
            'source_context' => ['value_span_id' => $row,
                'unit_span_id' => $unit === '%' ? $row : 'E027',
                'period_span_id' => 'E028', 'subject_span_id' => 'E025'],
            'identity' => ['concept' => $concept, 'scope' => 'Northstar Distribution Ltd',
                'metric_type' => $type, 'quantity_kind' => 'absolute',
                'aggregation' => $aggregation, 'value_basis' => 'actual', 'period' => $period],
        ];
    }
}
foreach ([['87%', 'actual'], ['95%', 'target']] as [$value, $basis]) {
    $kpis[] = [
        'label' => 'On-time deliveries', 'value' => $value, 'unit' => '%',
        'trend' => null, 'trendValue' => null,
        'source_context' => ['value_span_id' => 'E048', 'unit_span_id' => 'E048',
            'period_span_id' => 'E046', 'subject_span_id' => 'E048'],
        'identity' => ['concept' => 'on-time delivery rate', 'scope' => 'deliveries',
            'metric_type' => 'percentage', 'quantity_kind' => 'absolute',
            'aggregation' => null, 'value_basis' => $basis, 'period' => 'Q3 2026'],
    ];
}

return ['kpis' => $kpis, 'insights' => []];
