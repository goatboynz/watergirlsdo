<?php
require_once 'auth.php';
require_once 'init_db.php';
require_once 'ha_api.php';

$pdo = initializeDatabase();

// Current Time
$now = new DateTime();
$currentTime = $now->format('H:i');
$currentDay = $now->format('N'); // 1 (Mon) to 7 (Sun)

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
    // If none today later, look for first tomorrow
    $stmt = $pdo->prepare("SELECT e.*, z.name as zone_name, r.name as room_name 
                          FROM IrrigationEvents e 
                          JOIN Zones z ON e.zone_id = z.id 
                          JOIN Rooms r ON z.room_id = r.id 
                          WHERE e.enabled = 1 
                          ORDER BY e.start_time ASC LIMIT 1");
    $stmt->execute();
    $nextEvent = $stmt->fetch(PDO::FETCH_ASSOC);
}

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
            padding: 4rem 2rem;
            border-radius: 30px;
            margin-bottom: 2rem;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }
        .hero h1 { font-size: 3rem; margin: 0; }
        .hero p { opacity: 0.8; font-size: 1.2rem; }
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
            <p>Welcome to the Collective</p>
            <h1>Irrigation Dashboard</h1>
        </section>

        <div class="dashboard-grid">
            <!-- Next Watering Card -->
            <article class="card" style="grid-column: span 2;">
                <h2>🕒 Next Watering Event</h2>
                <?php if ($nextEvent): ?>
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div class="stat-value"><?= $nextEvent['start_time'] ?></div>
                        <p><strong><?= htmlspecialchars($nextEvent['room_name']) ?></strong> - <?= htmlspecialchars($nextEvent['zone_name']) ?></p>
                        <span class="badge badge-<?= strtolower($nextEvent['event_type']) ?>"><?= $nextEvent['event_type'] ?> Event</span>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:1.5rem; font-weight:600;"><?= floor($nextEvent['duration_seconds']/60) ?>m <?= $nextEvent['duration_seconds']%60 ?>s</div>
                        <small style="color:var(--text-dim)"><?= $nextEvent['days_of_week'] == '1,2,3,4,5,6,7' ? 'Every Day' : 'Custom Days' ?></small>
                    </div>
                </div>
                <?php else: ?>
                <div class="stat-value">No Events</div>
                <p>Add events in the Rooms section to get started.</p>
                <?php endif; ?>
            </article>

            <!-- Stats Card -->
            <article class="card">
                <h2>📊 System Overview</h2>
                <div style="display:flex; flex-direction:column; gap:1rem;">
                    <div style="display:flex; justify-content:space-between;">
                        <span>Active Rooms</span>
                        <strong style="color:var(--emerald)"><?= $roomCount ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between;">
                        <span>Configured Zones</span>
                        <strong style="color:var(--emerald)"><?= $zoneCount ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between;">
                        <span>Scheduled Timers</span>
                        <strong style="color:var(--emerald)"><?= $eventCount ?></strong>
                    </div>
                </div>
                <button class="btn btn-secondary" style="width:100%; margin-top:2rem;" onclick="location.href='irrigation.php'">Configure System</button>
            </article>
        </div>

        <section style="margin-top:3rem;">
            <h2>Upcoming for Today</h2>
            <div class="dashboard-grid">
                <?php
                $stmt = $pdo->prepare("SELECT e.*, z.name as zone_name, r.name as room_name 
                                      FROM IrrigationEvents e 
                                      JOIN Zones z ON e.zone_id = z.id 
                                      JOIN Rooms r ON z.room_id = r.id 
                                      WHERE e.enabled = 1 AND e.start_time >= ? 
                                      ORDER BY e.start_time ASC LIMIT 6");
                $stmt->execute([$currentTime]);
                $todaysEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($todaysEvents as $ev): ?>
                <div class="zone-card" style="background:var(--card-bg);">
                    <div style="display:flex; justify-content:space-between;">
                        <strong><?= $ev['start_time'] ?></strong>
                        <span class="badge badge-<?= strtolower($ev['event_type']) ?>"><?= $ev['event_type'] ?></span>
                    </div>
                    <div style="margin: 0.5rem 0;"><?= htmlspecialchars($ev['zone_name']) ?></div>
                    <small style="color:var(--text-dim)"><?= htmlspecialchars($ev['room_name']) ?></small>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>
