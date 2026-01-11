<?php
require_once 'auth.php';
require_once 'init_db.php';
require_once 'ha_api.php';

$pdo = initializeDatabase();

header('Content-Type: application/json');

$entity_ids = explode(',', $_GET['entity_id'] ?? '');
$range = $_GET['range'] ?? '24h'; // 1h, 24h, 7d

if (empty($entity_ids)) {
    echo json_encode(['error' => 'Missing entity_id']);
    exit;
}

$now = new DateTime('now', new DateTimeZone('UTC'));
switch ($range) {
    case '1h': $now->modify('-1 hour'); break;
    case '24h': $now->modify('-24 hours'); break;
    case '7d': $now->modify('-7 days'); break;
    default: $now->modify('-24 hours');
}

$start_time = $now->format('Y-m-d\TH:i:s\Z');
$history = ha_get_history($entity_ids, $start_time);

$labels = [];
$datasets = [];

if ($history && is_array($history)) {
    foreach ($history as $idx => $entity_history) {
        $entity_id = $entity_ids[$idx];
        $data = [];
        foreach ($entity_history as $entry) {
            if ($idx === 0) {
                $labels[] = date('H:i', strtotime($entry['last_changed']));
            }
            $val = $entry['state'];
            $data[] = is_numeric($val) ? round(floatval($val), 1) : 0;
        }
        $datasets[$entity_id] = $data;
    }
}

echo json_encode([
    'labels' => $labels,
    'datasets' => $datasets
]);
