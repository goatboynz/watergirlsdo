<?php
require_once 'auth.php';
require_once 'init_db.php';
require_once 'ha_api.php';

$pdo = initializeDatabase();

function calculateVPD($temp, $humidity) {
    if ($temp === null || $humidity === null || $temp === '' || $humidity === '') return null;
    $temp = floatval($temp);
    $humidity = floatval($humidity);
    $vp_sat = 0.61078 * exp((17.27 * $temp) / ($temp + 237.3));
    $vpd = $vp_sat * (1 - $humidity / 100);
    return round($vpd, 2);
}

// Fetch HA States
$ha_entities = ha_get_entities();
$entities_map = [];
foreach ($ha_entities as $e) { 
    $val = $e['state'] ?? '--';
    if (is_numeric($val)) $val = round(floatval($val), 1);
    $entities_map[$e['entity_id']] = $val; 
}

// Stats for dashboard
$stats1d = $pdo->query("SELECT SUM(volume_ml) FROM IrrigationLogs WHERE date(start_time) = date('now')")->fetchColumn() ?: 0;
$stats7d = $pdo->query("SELECT SUM(volume_ml) FROM IrrigationLogs WHERE date(start_time) >= date('now', '-7 days')")->fetchColumn() ?: 0;

// Last 3 events
$last3 = $pdo->query("SELECT l.*, z.name as zone_name, r.name as room_name 
                      FROM IrrigationLogs l
                      JOIN Zones z ON l.zone_id = z.id 
                      JOIN Rooms r ON z.room_id = r.id 
                      ORDER BY l.start_time DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);

// Next 3 events (for today)
$now_time = date('H:i');
// 0=Sun, 6=Sat in PHP w, but our DB uses 1=Mon, 7=Sun.
$day_map = [0=>7, 1=>1, 2=>2, 3=>3, 4=>4, 5=>5, 6=>6];
$db_day = $day_map[intval(date('w'))];

$next3 = $pdo->prepare("SELECT e.*, z.name as zone_name, r.name as room_name 
                       FROM IrrigationEvents e 
                       JOIN Zones z ON e.zone_id = z.id 
                       JOIN Rooms r ON z.room_id = r.id 
                       WHERE e.enabled = 1 AND e.start_time > ? 
                       ORDER BY e.start_time ASC LIMIT 3");
$next3->execute([$now_time]);
$next_events = $next3->fetchAll(PDO::FETCH_ASSOC);

// Rooms
$rooms = $pdo->query("SELECT * FROM Rooms ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - Waterd Girls Do</title>
    <link rel="stylesheet" href="css/waterd.css">
    <style>
        .dash-hero { background: var(--primary-gradient); padding: 2.5rem; border-radius: 32px; margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; }
        .dash-hero h1 { margin: 0; font-size: 2.5rem; }
        .event-card-stack { display: flex; flex-direction: column; gap: 0.8rem; }
        .event-mini-card { background: var(--glass-bg); padding: 1rem; border-radius: 16px; border: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center; }
        .room-stat-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; margin-top: 1rem; }
        .room-stat-item { background: rgba(0,0,0,0.2); padding: 0.6rem; border-radius: 12px; text-align: center; }
        .room-stat-item small { display: block; font-size: 0.6rem; color: var(--text-dim); text-transform: uppercase; }
        .room-stat-item span { font-weight: 700; font-size: 0.9rem; }
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
                <li><a href="history.php">History</a></li>
            </ul>
        </nav>
    </header>

    <main class="container">
        <section class="dash-hero">
            <div>
                <small style="text-transform:uppercase; letter-spacing:2px; opacity:0.8;">Facility Overview</small>
                <h1>Welcome back.</h1>
            </div>
            <div style="text-align:right;">
                <div style="font-size:1.5rem; font-weight:800;"><?= number_format($stats1d/1000, 1) ?>L</div>
                <small style="opacity:0.7;">Distributed Today</small>
            </div>
        </section>

        <div class="dashboard-grid">
            <!-- Event Timeline -->
            <div style="grid-column: span 8;">
                <div class="dashboard-grid" style="grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <article class="card">
                        <h2>🕒 Next 3 Shots</h2>
                        <div class="event-card-stack">
                            <?php foreach($next_events as $ev): ?>
                            <div class="event-mini-card">
                                <div>
                                    <strong><?= $ev['start_time'] ?></strong><br>
                                    <small><?= $ev['zone_name'] ?></small>
                                </div>
                                <span class="badge badge-<?= strtolower($ev['event_type']) ?>"><?= $ev['event_type'] ?></span>
                            </div>
                            <?php endforeach; ?>
                            <?php if(empty($next_events)): ?><p style="color:var(--text-dim);">No more events today.</p><?php endif; ?>
                        </div>
                    </article>

                    <article class="card">
                        <h2>✅ Last 3 Shots</h2>
                        <div class="event-card-stack">
                            <?php foreach($last3 as $ev): ?>
                            <div class="event-mini-card" style="border-left: 3px solid var(--emerald);">
                                <div>
                                    <strong><?= date('H:i', strtotime($ev['start_time'])) ?></strong><br>
                                    <small><?= $ev['zone_name'] ?></small>
                                </div>
                                <div style="text-align:right;">
                                    <span style="color:var(--gold); font-weight:700;"><?= number_format($ev['volume_ml'], 0) ?>ml</span><br>
                                    <small class="badge badge-<?= strtolower($ev['event_type']) ?>" style="font-size:0.5rem;"><?= $ev['event_type'] ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if(empty($last3)): ?><p style="color:var(--text-dim);">No history yet.</p><?php endif; ?>
                        </div>
                    </article>
                </div>

                <h2 style="margin-top:2rem;">Room Command</h2>
                <div class="dashboard-grid" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));">
                    <?php if ($rooms): foreach($rooms as $r): 
                        $temp = $entities_map[$r['temp_sensor_id'] ?? ''] ?? '--';
                        $hum = $entities_map[$r['humidity_sensor_id'] ?? ''] ?? '--';
                        $vpd = calculateVPD($temp, $hum) ?? '--';
                        
                        // Get first zone moisture/ec for room overview
                        $zstmt = $pdo->prepare("SELECT moisture_sensor_id, ec_sensor_id FROM Zones WHERE room_id = ? LIMIT 1");
                        $zstmt->execute([$r['id']]);
                        $zi = $zstmt->fetch(PDO::FETCH_ASSOC);
                        $vwc = $entities_map[$zi['moisture_sensor_id'] ?? ''] ?? '--';
                        $ec = $entities_map[$zi['ec_sensor_id'] ?? ''] ?? '--';
                    ?>
                    <article class="card">
                        <div style="display:flex; justify-content:space-between; align-items:start;">
                            <h3 style="margin:0;"><?= htmlspecialchars($r['name']) ?></h3>
                            <div class="vpd-badge" style="font-size:0.8rem; padding:0.2rem 0.6rem;"><?= $vpd ?> kPa</div>
                        </div>
                        <div class="room-stat-grid">
                            <div class="room-stat-item"><small>Temp</small><span><?= $temp ?>°C</span></div>
                            <div class="room-stat-item"><small>RH</small><span><?= $hum ?>%</span></div>
                            <div class="room-stat-item"><small>Soil VWC</small><span><?= $vwc ?>%</span></div>
                            <div class="room-stat-item"><small>Soil EC</small><span><?= $ec ?></span></div>
                        </div>
                        <a href="irrigation.php#room-group-<?= $r['id'] ?>" class="btn btn-secondary" style="width:100%; margin-top:1rem; padding:0.5rem;">Control Room</a>
                    </article>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- Side Quick Prime -->
            <div style="grid-column: span 4;">
                <article class="card" style="height:100%;">
                    <h2>⚡ Quick Prime</h2>
                    <p style="font-size:0.8rem; color:var(--text-dim);">Activate pumps for 5s testing.</p>
                    <div class="event-card-stack">
                    <?php
                        $zs = $pdo->query("SELECT z.*, r.name as room_name FROM Zones z JOIN Rooms r ON z.room_id = r.id ORDER BY r.name, z.name")->fetchAll(PDO::FETCH_ASSOC);
                        foreach($zs as $z):
                    ?>
                        <form method="POST" action="irrigation.php" style="margin:0;">
                            <input type="hidden" name="action" value="prime">
                            <input type="hidden" name="zone_id" value="<?= $z['id'] ?>">
                            <button type="submit" class="prime-btn" style="padding:0.7rem; border-radius:12px;">
                                <small><?= $z['room_name'] ?></small><br>
                                <strong><?= $z['name'] ?></strong>
                            </button>
                        </form>
                    <?php endforeach; ?>
                    </div>
                </article>
            </div>
        </div>
    </main>
</body>
</html>
