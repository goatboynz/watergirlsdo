<?php
require_once 'auth.php';
require_once 'init_db.php';
require_once 'ha_api.php';

$pdo = initializeDatabase();

// Current Time
$now = new DateTime();
$currentTime = $now->format('H:i');
$todayDate = $now->format('Y-m-d');

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

// Get Last Run (from Logs)
$lastRun = $pdo->query("SELECT l.*, z.name as zone_name, r.name as room_name 
                       FROM IrrigationLogs l
                       JOIN Zones z ON l.zone_id = z.id
                       JOIN Rooms r ON z.room_id = r.id
                       ORDER BY l.start_time DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Daily Stats
$totalRuntimeToday = $pdo->query("SELECT SUM(duration_seconds) FROM IrrigationLogs WHERE date(start_time) = date('now')")->fetchColumn() ?: 0;

// Stats
$roomCount = $pdo->query("SELECT COUNT(*) FROM Rooms")->fetchColumn();
$zoneCount = $pdo->query("SELECT COUNT(*) FROM Zones")->fetchColumn();
$eventCount = $pdo->query("SELECT COUNT(*) FROM IrrigationEvents WHERE enabled = 1")->fetchColumn();

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Waterd Girls Do - Dashboard</title>
    <link rel="stylesheet" href="css/waterd.css">
    <style>
        .hero {
            background: var(--primary-gradient);
            padding: 3rem 2rem;
            border-radius: 30px;
            margin-bottom: 2rem;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }
        .hero h1 { font-size: 2.5rem; margin: 0; }
        .hero p { opacity: 0.8; margin-bottom: 0.5rem; }
        
        .runtime-chip {
            background: rgba(46, 204, 113, 0.2);
            border: 1px solid var(--emerald);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            display: inline-block;
            margin-top: 1rem;
        }
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
            <p>High Performance Irrigation System</p>
            <h1>System Dashboard</h1>
            <div class="runtime-chip">
                💧 <strong><?= floor($totalRuntimeToday/60) ?>m <?= $totalRuntimeToday%60 ?>s</strong> pumped today
            </div>
        </section>

        <div class="dashboard-grid">
            <!-- Next Watering Card -->
            <article class="card" style="grid-column: span 2;">
                <h2>🕒 Next Watering</h2>
                <?php if ($nextEvent): ?>
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div class="stat-value"><?= $nextEvent['start_time'] ?></div>
                        <p><strong><?= htmlspecialchars($nextEvent['room_name']) ?></strong> - <?= htmlspecialchars($nextEvent['zone_name']) ?></p>
                        <span class="badge badge-<?= strtolower($nextEvent['event_type']) ?>"><?= $nextEvent['event_type'] ?> Event</span>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:1.5rem; font-weight:600;"><?= floor($nextEvent['duration_seconds']/60) ?>m <?= $nextEvent['duration_seconds']%60 ?>s</div>
                        <small style="color:var(--text-dim)">Enabled: <?= $nextEvent['enabled'] ? 'Yes' : 'No' ?></small>
                    </div>
                </div>
                <?php else: ?>
                <div class="stat-value">No Events</div>
                <p>All events are disabled or none scheduled.</p>
                <?php endif; ?>
            </article>

            <!-- Last Watering Card -->
            <article class="card">
                <h2>🔙 Last Finished</h2>
                <?php if ($lastRun): ?>
                <div>
                    <div style="font-size:1.5rem; font-weight:800; color:var(--gold); margin: 0.5rem 0;">
                        <?= date('H:i', strtotime($lastRun['start_time'])) ?>
                    </div>
                    <p style="margin:0;"><strong><?= htmlspecialchars($lastRun['zone_name']) ?></strong></p>
                    <small style="color:var(--text-dim)"><?= htmlspecialchars($lastRun['room_name']) ?></small>
                    <div style="margin-top:0.5rem; font-size:0.8rem;">Ran for <?= $lastRun['duration_seconds'] ?>s</div>
                </div>
                <?php else: ?>
                <p>No logged data yet.</p>
                <?php endif; ?>
            </article>
        </div>

        <div class="dashboard-grid" style="margin-top:1.5rem;">
             <!-- Stats Overview -->
             <article class="card">
                <h2>📊 Overview</h2>
                <div style="display:flex; flex-direction:column; gap:0.8rem;">
                    <div style="display:flex; justify-content:space-between;">
                        <span>Rooms</span>
                        <strong style="color:var(--emerald)"><?= $roomCount ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between;">
                        <span>Zones</span>
                        <strong style="color:var(--emerald)"><?= $zoneCount ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between;">
                        <span>Active Events</span>
                        <strong style="color:var(--emerald)"><?= $eventCount ?></strong>
                    </div>
                </div>
            </article>

            <!-- Room List with Upcoming -->
            <?php foreach ($rooms as $room): ?>
            <article class="card">
                <h2>🏠 <?= htmlspecialchars($room['name']) ?></h2>
                <div style="margin-top:1rem;">
                    <small style="color:var(--text-dim); display:block; margin-bottom:0.5rem;">NEXT UP:</small>
                    <?php
                    $stmt = $pdo->prepare("SELECT e.*, z.name as zone_name FROM IrrigationEvents e 
                                          JOIN Zones z ON e.zone_id = z.id 
                                          WHERE z.room_id = ? AND e.enabled = 1 AND e.start_time > ?
                                          ORDER BY e.start_time ASC LIMIT 1");
                    $stmt->execute([$room['id'], $currentTime]);
                    $rNext = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($rNext): ?>
                        <div style="display:flex; justify-content:space-between; align-items:center; background:rgba(255,255,255,0.05); padding:0.5rem; border-radius:10px;">
                            <strong><?= $rNext['start_time'] ?></strong>
                            <small><?= htmlspecialchars($rNext['zone_name']) ?></small>
                        </div>
                    <?php else: ?>
                        <small>No more events today.</small>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>
