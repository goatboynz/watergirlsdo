<?php
require_once 'auth.php';
require_once 'init_db.php';
require_once 'ha_api.php';

$pdo = initializeDatabase();

header('Content-Type: application/json');

$entity_id = $_GET['entity_id'] ?? '';
$range = $_GET['range'] ?? '24h'; // 1h, 24h, 7d

if (!$entity_id) {
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
$history = ha_get_history([$entity_id], $start_time);

$labels = [];
$data = [];

if ($history && is_array($history) && isset($history[0])) {
    foreach ($history[0] as $entry) {
        $labels[] = date('H:i', strtotime($entry['last_changed']));
        $val = $entry['state'];
        $data[] = is_numeric($val) ? round(floatval($val), 1) : 0;
    }
}

echo json_encode([
    'labels' => $labels,
    'data' => $data
]);
