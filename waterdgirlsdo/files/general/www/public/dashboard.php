<?php
require_once 'auth.php';
require_once 'init_db.php';
require_once 'ha_api.php';

$pdo = initializeDatabase();

// Handle Actions (Prime from Dashboard)
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
    header("Location: dashboard.php");
    exit;
}

// Current Time
$now = new DateTime();
$currentTime = $now->format('H:i');

// Get Next Event
$stmt = $pdo->prepare("SELECT e.*, z.name as zone_name, r.name as room_name 
                      FROM IrrigationEvents e 
                      JOIN Zones z ON e.zone_id = z.id 
                      JOIN Rooms r ON z.room_id = r.id 
                      WHERE e.enabled = 1 AND e.start_time > ? 
                      ORDER BY e.start_time ASC LIMIT 1");
$stmt->execute([$currentTime]);
$nextEvent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$nextEvent) {
    $stmt = $pdo->prepare("SELECT e.*, z.name as zone_name, r.name as room_name 
                          FROM IrrigationEvents e 
                          JOIN Zones z ON e.zone_id = z.id 
                          JOIN Rooms r ON z.room_id = r.id 
                          WHERE e.enabled = 1 
                          ORDER BY e.start_time ASC LIMIT 1");
    $stmt->execute();
    $nextEvent = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get Last Run
$lastRun = $pdo->query("SELECT l.*, z.name as zone_name, r.name as room_name 
                       FROM IrrigationLogs l
                       JOIN Zones z ON l.zone_id = z.id
                       JOIN Rooms r ON z.room_id = r.id
                       ORDER BY l.start_time DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Volume Stats
$totalVolumeTodayML = $pdo->query("SELECT SUM(volume_ml) FROM IrrigationLogs WHERE date(start_time) = date('now')")->fetchColumn() ?: 0;
$totalLitresToday = $totalVolumeTodayML / 1000;

$p1TodayML = $pdo->query("SELECT SUM(volume_ml) FROM IrrigationLogs WHERE date(start_time) = date('now') AND event_type = 'P1'")->fetchColumn() ?: 0;
$p2TodayML = $pdo->query("SELECT SUM(volume_ml) FROM IrrigationLogs WHERE date(start_time) = date('now') AND event_type = 'P2'")->fetchColumn() ?: 0;

$weeklyVolumeML = $pdo->query("SELECT SUM(volume_ml) FROM IrrigationLogs WHERE date(start_time) >= date('now', '-7 days')")->fetchColumn() ?: 0;
$weeklyLitres = $weeklyVolumeML / 1000;

// All Zones for Quick Prime
$allZones = $pdo->query("SELECT z.*, r.name as room_name FROM Zones z JOIN Rooms r ON z.room_id = r.id ORDER BY r.name, z.name")->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Waterd Girls Do - Dashboard</title>
    <link rel="stylesheet" href="css/waterd.css">
    <style>
        .hero { background: var(--primary-gradient); padding: 3rem 2rem; border-radius: 30px; margin-bottom: 2rem; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.4); }
        .hero h1 { font-size: 2.5rem; margin: 0; }
        .runtime-chip { background: rgba(46, 204, 113, 0.2); border: 1px solid var(--emerald); padding: 0.5rem 1rem; border-radius: 50px; display: inline-block; margin-top: 1rem; }
        .prime-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.8rem; margin-top: 1rem; }
        .prime-btn { background: rgba(255,215,0,0.1); border: 1px solid var(--gold); color: var(--gold); padding: 0.8rem; border-radius: 12px; cursor: pointer; text-align: center; transition: all 0.3s; }
        .prime-btn:hover { background: var(--gold); color: black; }
        .split-bar { display: flex; height: 10px; background: rgba(255,255,255,0.1); border-radius: 5px; overflow: hidden; margin: 1rem 0; }
        .p1-bar { background: var(--emerald); height: 100%; transition: width 0.5s; }
        .p2-bar { background: var(--gold); height: 100%; transition: width 0.5s; }
    </style>
</head>
<body>
    <header>
        <div class="logo-text">WATERD GIRLS DO</div>
        <nav>
            <ul>
                <li><a href="dashboard.php" class="active">Dashboard</a></li>
                <li><a href="irrigation.php">Rooms</a></li>
            </ul>
        </nav>
    </header>

    <main class="container">
        <section class="hero">
            <p>Crop Steering Console: <?= date('H:i') ?></p>
            <h1>Feed Analytics</h1>
            <div class="runtime-chip">
                🥛 <strong><?= number_format($totalLitresToday, 2) ?> Litres</strong> distributed today
            </div>
        </section>

        <div class="dashboard-grid">
            <article class="card" style="grid-column: span 2;">
                <h2>🕒 Next Steering Event</h2>
                <?php if ($nextEvent): ?>
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div class="stat-value"><?= $nextEvent['start_time'] ?></div>
                        <p><strong><?= htmlspecialchars($nextEvent['room_name']) ?></strong> - <?= htmlspecialchars($nextEvent['zone_name']) ?></p>
                    </div>
                    <div style="text-align:right;">
                        <span class="badge badge-<?= strtolower($nextEvent['event_type']) ?>"><?= $nextEvent['event_type'] ?> Phase</span>
                        <div style="font-size:1.5rem; font-weight:600; margin-top:0.5rem;"><?= floor($nextEvent['duration_seconds']/60) ?>m <?= $nextEvent['duration_seconds']%60 ?>s</div>
                    </div>
                </div>
                <?php else: ?>
                <div class="stat-value">Idle</div>
                <p>No strategy active.</p>
                <?php endif; ?>
            </article>

            <article class="card">
                <h2>📈 Phase Distribution</h2>
                <div style="font-size: 0.9rem; color: var(--text-dim);">Today's Volume split:</div>
                <div class="split-bar">
                    <?php 
                        $total = max(1, $p1TodayML + $p2TodayML);
                        $p1Pct = ($p1TodayML / $total) * 100;
                        $p2Pct = ($p2TodayML / $total) * 100;
                    ?>
                    <div class="p1-bar" style="width: <?= $p1Pct ?>%;"></div>
                    <div class="p2-bar" style="width: <?= $p2Pct ?>%;"></div>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:0.8rem;">
                    <span><span style="color:var(--emerald);">●</span> P1: <?= number_format($p1TodayML/1000, 2) ?>L</span>
                    <span><span style="color:var(--gold);">●</span> P2: <?= number_format($p2TodayML/1000, 2) ?>L</span>
                </div>
                <div style="margin-top:1.5rem; border-top: 1px solid rgba(255,255,255,0.1); padding-top:1rem;">
                    <small style="color:var(--text-dim);">Weekly Total:</small>
                    <div style="font-size:1.5rem; font-weight:800; color:var(--emerald);"><?= number_format($weeklyLitres, 1) ?> Litres</div>
                </div>
            </article>
        </div>

        <section style="margin-top:2rem;">
            <h2>Quick Prime & Test</h2>
            <div class="prime-grid">
                <?php foreach ($allZones as $z): ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action" value="prime">
                    <input type="hidden" name="zone_id" value="<?= $z['id'] ?>">
                    <button type="submit" class="prime-btn">
                        <small style="font-size:0.6rem; opacity:0.8;"><?= htmlspecialchars($z['room_name']) ?></small><br>
                        <strong><?= htmlspecialchars($z['name']) ?></strong>
                    </button>
                </form>
                <?php endforeach; ?>
            </div>
        </section>

        <section style="margin-top:3rem;">
            <h2>Steering Log (Runoff & Maintenance)</h2>
            <div class="dashboard-grid">
                <?php
                $stmt = $pdo->prepare("SELECT l.*, z.name as zone_name, r.name as room_name 
                                      FROM IrrigationLogs l
                                      JOIN Zones z ON l.zone_id = z.id 
                                      JOIN Rooms r ON z.room_id = r.id 
                                      WHERE date(l.start_time) = date('now')
                                      ORDER BY l.start_time DESC LIMIT 6");
                $stmt->execute();
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($logs as $log): ?>
                <div class="zone-card" style="background:var(--card-bg); border-left: 4px solid <?= $log['event_type'] == 'P1' ? 'var(--emerald)' : 'var(--gold)' ?>;">
                    <div style="display:flex; justify-content:space-between;">
                        <strong><?= date('H:i', strtotime($log['start_time'])) ?></strong>
                        <span style="color:var(--gold); font-weight:800;"><?= number_format($log['volume_ml'], 0) ?> mL</span>
                    </div>
                    <div style="margin: 0.5rem 0; font-weight:600;"><?= htmlspecialchars($log['zone_name']) ?></div>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <small style="color:var(--text-dim)"><?= htmlspecialchars($log['room_name']) ?></small>
                        <span class="badge badge-<?= strtolower($log['event_type'] ?: 'p1') ?>" style="padding:0.1rem 0.3rem; font-size:0.6rem;"><?= $log['event_type'] ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>
