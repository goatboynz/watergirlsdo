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

// Daily Volume Stats
$totalVolumeTodayML = $pdo->query("SELECT SUM(volume_ml) FROM IrrigationLogs WHERE date(start_time) = date('now')")->fetchColumn() ?: 0;
$totalLitresToday = $totalVolumeTodayML / 1000;

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
            <p>System Online: <?= date('H:i') ?></p>
            <h1>Feed Logistics</h1>
            <div class="runtime-chip">
                🥛 <strong><?= number_format($totalLitresToday, 2) ?> Litres</strong> delivered today
            </div>
        </section>

        <div class="dashboard-grid">
            <article class="card" style="grid-column: span 2;">
                <h2>🕒 Next Feed</h2>
                <?php if ($nextEvent): ?>
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div class="stat-value"><?= $nextEvent['start_time'] ?></div>
                        <p><strong><?= htmlspecialchars($nextEvent['room_name']) ?></strong> - <?= htmlspecialchars($nextEvent['zone_name']) ?></p>
                    </div>
                    <div style="text-align:right;">
                        <span class="badge badge-<?= strtolower($nextEvent['event_type']) ?>"><?= $nextEvent['event_type'] ?> Shot</span>
                        <div style="font-size:1.5rem; font-weight:600; margin-top:0.5rem;"><?= floor($nextEvent['duration_seconds']/60) ?>m <?= $nextEvent['duration_seconds']%60 ?>s</div>
                    </div>
                </div>
                <?php else: ?>
                <div class="stat-value">Idle</div>
                <p>No active feeds scheduled.</p>
                <?php endif; ?>
            </article>

            <article class="card">
                <h2>🔙 Last Shot</h2>
                <?php if ($lastRun): ?>
                <div>
                    <div style="font-size:1.5rem; font-weight:800; color:var(--emerald); margin: 0.5rem 0;"><?= date('H:i', strtotime($lastRun['start_time'])) ?></div>
                    <p style="margin:0;"><strong><?= htmlspecialchars($lastRun['zone_name']) ?></strong></p>
                    <div style="margin-top:0.5rem; font-size:1.1rem; color:var(--gold);"><?= number_format($lastRun['volume_ml'], 0) ?> mL</div>
                </div>
                <?php else: ?>
                <p>Waiting for data...</p>
                <?php endif; ?>
            </article>
        </div>

        <section style="margin-top:2rem;">
            <h2>Quick Prime (5s Burst)</h2>
            <div class="prime-grid">
                <?php foreach ($allZones as $z): ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action" value="prime">
                    <input type="hidden" name="zone_id" value="<?= $z['id'] ?>">
                    <button type="submit" class="prime-btn">
                        <small><?= htmlspecialchars($z['room_name']) ?></small><br>
                        <strong><?= htmlspecialchars($z['name']) ?></strong>
                    </button>
                </form>
                <?php endforeach; ?>
            </div>
        </section>

        <section style="margin-top:3rem;">
            <h2>Daily Log</h2>
            <div class="dashboard-grid">
                <?php
                $stmt = $pdo->prepare("SELECT l.*, z.name as zone_name, r.name as room_name 
                                      FROM IrrigationLogs l
                                      JOIN Zones z ON l.zone_id = z.id 
                                      JOIN Rooms r ON z.room_id = r.id 
                                      WHERE date(l.start_time) = date('now')
                                      ORDER BY l.start_time DESC LIMIT 8");
                $stmt->execute();
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($logs as $log): ?>
                <div class="zone-card" style="background:var(--card-bg); border-left: 4px solid var(--emerald);">
                    <div style="display:flex; justify-content:space-between;">
                        <strong><?= date('H:i', strtotime($log['start_time'])) ?></strong>
                        <span style="color:var(--gold);"><?= number_format($log['volume_ml'], 0) ?> mL</span>
                    </div>
                    <div style="margin: 0.5rem 0;"><?= htmlspecialchars($log['zone_name']) ?></div>
                    <small style="color:var(--text-dim)"><?= htmlspecialchars($log['room_name']) ?> (<?= $log['duration_seconds'] ?>s)</small>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>
