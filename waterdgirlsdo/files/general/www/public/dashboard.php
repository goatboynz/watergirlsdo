<?php
require_once 'auth.php';
require_once 'init_db.php';
require_once 'ha_api.php';

$pdo = initializeDatabase();

function calculateVPD($temp, $humidity) {
    if ($temp === null || $humidity === null || $temp === '' || $humidity === '') return null;
    $vp_sat = 0.61078 * exp((17.27 * floatval($temp)) / (floatval($temp) + 237.3));
    $vpd = $vp_sat * (1 - floatval($humidity) / 100);
    return round($vpd, 2);
}

// Actions
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

// Fetch Sensors
$ha_entities = ha_get_entities();
$entities_map = [];
foreach ($ha_entities as $e) { $entities_map[$e['entity_id']] = $e['state'] ?? null; }

// Global Volume Stats
$stats1d = $pdo->query("SELECT SUM(volume_ml) FROM IrrigationLogs WHERE date(start_time) = date('now')")->fetchColumn() ?: 0;
$stats7d = $pdo->query("SELECT SUM(volume_ml) FROM IrrigationLogs WHERE date(start_time) >= date('now', '-7 days')")->fetchColumn() ?: 0;
$stats30d = $pdo->query("SELECT SUM(volume_ml) FROM IrrigationLogs WHERE date(start_time) >= date('now', '-30 days')")->fetchColumn() ?: 0;

// Graph (7 Days)
$graph_labels = []; $graph_data = [];
for ($i=6; $i>=0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $vol = $pdo->prepare("SELECT SUM(volume_ml) FROM IrrigationLogs WHERE date(start_time) = ?"); $vol->execute([$date]);
    $graph_labels[] = date('D', strtotime($date));
    $graph_data[] = round(($vol->fetchColumn() ?: 0) / 1000, 2);
}

// Next Event
$now_time = date('H:i');
$stmt = $pdo->prepare("SELECT e.*, z.name as zone_name, r.name as room_name FROM IrrigationEvents e JOIN Zones z ON e.zone_id = z.id JOIN Rooms r ON z.room_id = r.id WHERE e.enabled = 1 AND e.start_time > ? ORDER BY e.start_time ASC LIMIT 1");
$stmt->execute([$now_time]);
$next = $stmt->fetch(PDO::FETCH_ASSOC);

$rooms = $pdo->query("SELECT * FROM Rooms ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allZones = $pdo->query("SELECT z.*, r.name as room_name FROM Zones z JOIN Rooms r ON z.room_id = r.id ORDER BY r.name, z.name")->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - Waterd Girls Do</title>
    <link rel="stylesheet" href="css/waterd.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stat-group { display: flex; flex-direction: column; }
        .stat-label { font-size: 0.75rem; text-transform: uppercase; color: var(--text-dim); letter-spacing: 1px; }
        .huge-val { font-size: 3.5rem; font-weight: 800; letter-spacing: -2px; color: var(--text-main); line-height: 1; margin: 0.5rem 0; }
        .trend-up { color: var(--emerald); font-size: 0.9rem; font-weight: 600; }
        .room-env-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-top: 2rem; }
        .env-card { background: var(--card-bg); border-radius: 24px; padding: 1.5rem; border: 1px solid rgba(255,255,255,0.05); }
        .vpd-value { font-size: 2.5rem; font-weight: 800; color: var(--emerald); }
        .prime-btn { width: 100%; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.02); color: var(--text-main); padding: 1rem; border-radius: 16px; cursor: pointer; text-align: left; transition: all 0.2s; }
        .prime-btn:hover { background: rgba(255,215,0,0.1); border-color: var(--gold); }
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
        <div class="dashboard-grid">
            <!-- Main Stats -->
            <article class="card" style="grid-column: span 8;">
                <div style="display:flex; justify-content:space-between; align-items:start;">
                    <div class="stat-group">
                        <span class="stat-label">Daily Consumption</span>
                        <div class="huge-val"><?= number_format($stats1d/1000, 1) ?>L</div>
                        <span class="trend-up">↑ 7-Day Avg: <?= number_format($stats7d/7000, 1) ?>L</span>
                    </div>
                    <div style="width: 60%;">
                        <canvas id="mainChart" height="100"></canvas>
                    </div>
                </div>
            </article>

            <!-- Next Event -->
            <article class="card" style="grid-column: span 4; background: var(--primary-gradient);">
                <span class="stat-label" style="color: rgba(255,255,255,0.7);">Next Steering Event</span>
                <?php if ($next): ?>
                    <div class="huge-val" style="color: white; font-size: 4.5rem;"><?= $next['start_time'] ?></div>
                    <div style="background:rgba(255,255,255,0.2); padding:0.8rem; border-radius:12px; display:inline-block;">
                        <strong><?= $next['room_name'] ?></strong> • <?= $next['zone_name'] ?>
                    </div>
                    <div style="margin-top:1rem;"><span class="badge" style="background:white; color:black;"><?= $next['event_type'] ?> Phase</span></div>
                <?php else: ?>
                    <div class="huge-val" style="color: white;">IDLE</div>
                    <p style="color:rgba(255,255,255,0.7);">No strategy active.</p>
                <?php endif; ?>
            </article>

            <!-- 7/30 Day Cards -->
            <article class="card" style="grid-column: span 4;">
                <span class="stat-label">Weekly Total</span>
                <div class="stat-value" style="color: var(--gold);"><?= number_format($stats7d/1000, 1) ?>L</div>
                <p style="color:var(--text-dim); font-size:0.8rem;">Cumulative use (Last 7 Days)</p>
            </article>

            <article class="card" style="grid-column: span 4;">
                <span class="stat-label">Monthly Impact</span>
                <div class="stat-value"><?= number_format($stats30d/1000, 1) ?>L</div>
                <p style="color:var(--text-dim); font-size:0.8rem;">Facility footprint (Last 30 Days)</p>
            </article>

            <article class="card" style="grid-column: span 4;">
                <span class="stat-label">Active Rooms</span>
                <div class="stat-value"><?= count($rooms) ?></div>
                <p style="color:var(--text-dim); font-size:0.8rem;">Controlled environments</p>
            </article>
        </div>

        <h2 style="margin-top:3rem; font-size:1.5rem;">Environment Snapshot</h2>
        <div class="room-env-grid">
            <?php foreach ($rooms as $room): 
                $temp = $entities_map[$room['temp_sensor_id']] ?? null;
                $hum = $entities_map[$room['humidity_sensor_id']] ?? null;
                $vpd = calculateVPD($temp, $hum);
            ?>
            <div class="env-card">
                <div style="display:flex; justify-content:space-between; align-items:start;">
                    <h3 style="margin:0; font-size:1.1rem;"><?= htmlspecialchars($room['name']) ?></h3>
                    <span style="font-size:0.7rem; background:rgba(255,255,255,0.05); padding:0.2rem 0.5rem; border-radius:5px; color:var(--text-dim);">LIGHTS: <?= $room['lights_on'] ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-top:1rem;">
                    <div>
                        <div class="vpd-value"><?= $vpd ?: '--' ?></div>
                        <span class="stat-label">VPD (kPa)</span>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-weight:700; font-size:1.1rem;"><?= $temp ?: '--' ?>°C</div>
                        <div style="color:var(--text-dim); font-size:0.9rem;"><?= $hum ?: '--' ?>% RH</div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <h2 style="margin-top:3rem; font-size:1.5rem;">Manual Bypass & Priming</h2>
        <div class="dashboard-grid">
            <?php foreach ($allZones as $z): ?>
            <div style="grid-column: span 3;">
                <form method="POST">
                    <input type="hidden" name="action" value="prime">
                    <input type="hidden" name="zone_id" value="<?= $z['id'] ?>">
                    <button type="submit" class="prime-btn">
                        <small style="text-transform:uppercase; color:var(--text-dim); font-size:0.6rem;"><?= $z['room_name'] ?></small><br>
                        <strong><?= $z['name'] ?></strong>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </main>

    <script>
        const ctx = document.getElementById('mainChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($graph_labels) ?>,
                datasets: [{
                    label: 'Litres',
                    data: <?= json_encode($graph_data) ?>,
                    borderColor: '#2ecc71',
                    borderWidth: 3,
                    tension: 0.4,
                    pointRadius: 0,
                    fill: true,
                    backgroundColor: 'rgba(46, 204, 113, 0.1)'
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    x: { display: false },
                    y: { display: false }
                }
            }
        });
    </script>
</body>
</html>
