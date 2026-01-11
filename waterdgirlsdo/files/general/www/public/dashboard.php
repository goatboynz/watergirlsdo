<?php
require_once 'auth.php';
require_once 'init_db.php';
require_once 'ha_api.php';

$pdo = initializeDatabase();

function calculateVPD($temp, $humidity) {
    if ($temp === null || $humidity === null) return null;
    $vp_sat = 0.61078 * exp((17.27 * floatval($temp)) / (floatval($temp) + 237.3));
    $vpd = $vp_sat * (1 - floatval($humidity) / 100);
    return round($vpd, 3);
}

// Handle Actions (Prime)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'prime') {
    $zone_id = $_POST['zone_id'];
    $stmt = $pdo->prepare("SELECT pump_entity_id, solenoid_entity_id FROM Zones WHERE id = ?");
    $stmt->execute([$zone_id]);
    $z = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($z) {
        if ($z['solenoid_entity_id']) ha_set_state($z['solenoid_entity_id'], 'on');
        ha_set_state($z['pump_entity_id'], 'on');
        usleep(5000000); 
        ha_set_state($z['pump_entity_id'], 'off');
        if ($z['solenoid_entity_id']) ha_set_state($z['solenoid_entity_id'], 'off');
    }
    header("Location: dashboard.php"); exit;
}

// Fetch HA States for Sensors
$ha_entities = ha_get_entities();
$entities_map = [];
foreach ($ha_entities as $e) { $entities_map[$e['entity_id']] = $e['state']; }

// Room & Sensor Analytics
$rooms = $pdo->query("SELECT * FROM Rooms ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$room_stats = [];
foreach ($rooms as $room) {
    $temp = isset($entities_map[$room['temp_sensor_id']]) ? $entities_map[$room['temp_sensor_id']] : null;
    $hum = isset($entities_map[$room['humidity_sensor_id']]) ? $entities_map[$room['humidity_sensor_id']] : null;
    $room_stats[$room['id']] = [
        'temp' => $temp,
        'hum' => $hum,
        'vpd' => calculateVPD($temp, $hum),
        'lights' => ($room['lights_on'] && $room['lights_off']) ? $room['lights_on'].' - '.$room['lights_off'] : 'Not Set'
    ];
}

// Global Volume Stats
$stats1d = $pdo->query("SELECT SUM(volume_ml) FROM IrrigationLogs WHERE date(start_time) = date('now')")->fetchColumn() ?: 0;
$stats7d = $pdo->query("SELECT SUM(volume_ml) FROM IrrigationLogs WHERE date(start_time) >= date('now', '-7 days')")->fetchColumn() ?: 0;
$stats30d = $pdo->query("SELECT SUM(volume_ml) FROM IrrigationLogs WHERE date(start_time) >= date('now', '-30 days')")->fetchColumn() ?: 0;

// Graph Data (Last 7 Days)
$graph_labels = []; $graph_data = [];
for ($i=6; $i>=0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $vol = $pdo->prepare("SELECT SUM(volume_ml) FROM IrrigationLogs WHERE date(start_time) = ?");
    $vol->execute([$date]);
    $graph_labels[] = date('D', strtotime($date));
    $graph_data[] = round(($vol->fetchColumn() ?: 0) / 1000, 2);
}

// Next Event
$now_time = date('H:i');
$stmt = $pdo->prepare("SELECT e.*, z.name as zone_name, r.name as room_name FROM IrrigationEvents e JOIN Zones z ON e.zone_id = z.id JOIN Rooms r ON z.room_id = r.id WHERE e.enabled = 1 AND e.start_time > ? ORDER BY e.start_time ASC LIMIT 1");
$stmt->execute([$now_time]);
$next = $stmt->fetch(PDO::FETCH_ASSOC);

$allZones = $pdo->query("SELECT z.*, r.name as room_name FROM Zones z JOIN Rooms r ON z.room_id = r.id ORDER BY r.name, z.name")->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Waterd Girls Do - Dashboard</title>
    <link rel="stylesheet" href="css/waterd.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .vpd-tag { font-size: 2rem; font-weight: 800; color: var(--emerald); }
        .sensor-card { background: rgba(0,0,0,0.3); padding: 1rem; border-radius: 15px; border: 1px solid rgba(255,255,255,0.05); }
        .stat-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 2rem; }
        .stat-box { background: var(--card-bg); padding: 1.5rem; border-radius: 20px; text-align: center; }
        .stat-box small { color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; }
        .stat-box div { font-size: 1.8rem; font-weight: 800; margin-top: 0.5rem; }
    </style>
</head>
<body>
    <header>
        <div class="logo-text">WATERD GIRLS DO</div>
        <nav>
            <ul>
                <li><a href="dashboard.php" class="active">Dashboard</a></li>
                <li><a href="irrigation.php">Rooms</a></li>
                <li><a href="calendar.php">Calendar</a></li>
            </ul>
        </nav>
    </header>

    <main class="container">
        <div class="stat-grid">
            <div class="stat-box">
                <small>Today</small>
                <div style="color:var(--emerald);"><?= number_format($stats1d/1000, 1) ?>L</div>
            </div>
            <div class="stat-box">
                <small>7 Days</small>
                <div><?= number_format($stats7d/1000, 1) ?>L</div>
            </div>
            <div class="stat-box">
                <small>30 Days</small>
                <div style="color:var(--gold);"><?= number_format($stats30d/1000, 1) ?>L</div>
            </div>
        </div>

        <div class="dashboard-grid">
            <article class="card" style="grid-column: span 2;">
                <h2>📊 Water Usage Trend (Litres)</h2>
                <canvas id="usageChart" style="max-height: 250px;"></canvas>
            </article>

            <article class="card">
                <h2>🕒 Next Event</h2>
                <?php if ($next): ?>
                    <div class="stat-value"><?= $next['start_time'] ?></div>
                    <p><strong><?= $next['room_name'] ?></strong> - <?= $next['zone_name'] ?></p>
                    <span class="badge badge-<?= strtolower($next['event_type']) ?>"><?= $next['event_type'] ?> Shot</span>
                <?php else: ?>
                    <div class="stat-value">Idle</div>
                <?php endif; ?>
            </article>
        </div>

        <h2 style="margin-top:2rem;">Room Environment & Sensors</h2>
        <div class="dashboard-grid">
            <?php foreach($rooms as $room): 
                $rs = $room_stats[$room['id']];
            ?>
            <article class="card">
                <h3 style="margin:0;"><?= htmlspecialchars($room['name']) ?></h3>
                <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-top:1rem;">
                    <div>
                        <div class="vpd-tag"><?= $rs['vpd'] ?: '--' ?> <small style="font-size:0.8rem; vertical-align:middle; color:var(--text-dim);">VPD</small></div>
                        <div style="font-size:0.9rem; color:var(--text-dim);"><?= $rs['temp'] ?>°C / <?= $rs['hum'] ?>% RH</div>
                    </div>
                    <div style="text-align:right;">
                        <small style="color:var(--gold);">Lights</small><br>
                        <strong><?= $rs['lights'] ?></strong>
                    </div>
                </div>
                <!-- Zone Sensors -->
                <?php 
                    $zs = $pdo->prepare("SELECT * FROM Zones WHERE room_id = ?"); $zs->execute([$room['id']]);
                    foreach ($zs->fetchAll(PDO::FETCH_ASSOC) as $z): 
                        $m = isset($entities_map[$z['moisture_sensor_id']]) ? $entities_map[$z['moisture_sensor_id']] : null;
                        $ec = isset($entities_map[$z['ec_sensor_id']]) ? $entities_map[$z['ec_sensor_id']] : null;
                        if ($m || $ec):
                ?>
                    <div class="zone-metrics" style="background:rgba(255,255,255,0.03); margin-top:0.8rem;">
                        <div style="display:flex; justify-content:space-between;">
                            <small><?= $z['name'] ?></small>
                            <span style="font-size:0.7rem;"><?= $m ? "VWC: $m%" : "" ?> <?= $ec ? " EC: $ec" : "" ?></span>
                        </div>
                    </div>
                <?php endif; endforeach; ?>
            </article>
            <?php endforeach; ?>
        </div>

        <section style="margin-top:2rem;">
            <h2>Quick Controls</h2>
            <div class="prime-grid">
                <?php foreach($allZones as $z): ?>
                <form method="POST"><input type="hidden" name="action" value="prime"><input type="hidden" name="zone_id" value="<?= $z['id'] ?>"><button class="prime-btn"><small><?= $z['room_name'] ?></small><br><strong><?= $z['name'] ?></strong></button></form>
                <?php endforeach; ?>
            </div>
        </section>
    </main>

    <script>
        const ctx = document.getElementById('usageChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($graph_labels) ?>,
                datasets: [{
                    label: 'Litres',
                    data: <?= json_encode($graph_data) ?>,
                    backgroundColor: '#2ecc71',
                    borderRadius: 8
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    </script>
</body>
</html>
