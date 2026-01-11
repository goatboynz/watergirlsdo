<?php
require_once 'auth.php';
require_once 'init_db.php';
$pdo = initializeDatabase();

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$rooms = $pdo->query("SELECT * FROM Rooms ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Waterd Girls Do - Weekly Calendar</title>
    <link rel="stylesheet" href="css/waterd.css">
    <style>
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1rem; margin-top: 2rem; }
        .day-column { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); border-radius: 15px; padding: 1rem; min-height: 500px; }
        .day-header { text-align: center; border-bottom: 2px solid var(--emerald); padding-bottom: 0.5rem; margin-bottom: 1rem; font-weight: 800; color: var(--emerald); }
        .cal-event { background: var(--card-bg); border-left: 3px solid var(--emerald); padding: 0.5rem; border-radius: 8px; font-size: 0.75rem; margin-bottom: 0.5rem; transition: transform 0.2s; }
        .cal-event:hover { transform: scale(1.02); }
        .cal-event.p2 { border-left-color: var(--gold); }
        .cal-time { font-weight: 800; display: block; }
        .cal-room { color: var(--text-dim); font-size: 0.65rem; text-transform: uppercase; }
        @media (max-width: 1200px) { .calendar-grid { grid-template-columns: 1fr; } .day-column { min-height: auto; } }
    </style>
</head>
<body>
    <header>
        <div class="logo-text">WATERD GIRLS DO</div>
        <nav>
            <ul>
                <li><a href="dashboard.php">Dashboard</a></li>
                <li><a href="irrigation.php">Rooms</a></li>
                <li><a href="calendar.php" class="active">Calendar</a></li>
            </ul>
        </nav>
    </header>

    <main class="container">
        <h1>Weekly Strategic Calendar</h1>
        <p>Overview of all planned irrigation shots across the next 7 days.</p>

        <div class="calendar-grid">
            <?php foreach ($days as $idx => $dayName): 
                $dayId = $idx + 1; // 1=Mon, 7=Sun
            ?>
            <div class="day-column">
                <div class="day-header"><?= $dayName ?></div>
                <?php
                $stmt = $pdo->prepare("SELECT e.*, z.name as zone_name, r.name as room_name 
                                      FROM IrrigationEvents e 
                                      JOIN Zones z ON e.zone_id = z.id 
                                      JOIN Rooms r ON z.room_id = r.id 
                                      WHERE e.enabled = 1 AND FIND_IN_SET(?, e.days_of_week)
                                      ORDER BY e.start_time ASC");
                // SQLite doesn't have FIND_IN_SET, but we stored them as comma separated strings.
                // We'll use LIKE for simplicity or just fetch all and filter in PHP.
                // Since this is a small list, fetching all is fine.
                $allEvents = $pdo->query("SELECT e.*, z.name as zone_name, r.name as room_name 
                                          FROM IrrigationEvents e 
                                          JOIN Zones z ON e.zone_id = z.id 
                                          JOIN Rooms r ON z.room_id = r.id 
                                          WHERE e.enabled = 1 
                                          ORDER BY e.start_time ASC")->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($allEvents as $event): 
                    $activeDays = explode(',', $event['days_of_week']);
                    if (!in_array($dayId, $activeDays)) continue;
                ?>
                <div class="cal-event <?= strtolower($event['event_type']) ?>">
                    <span class="cal-time"><?= $event['start_time'] ?></span>
                    <div><?= htmlspecialchars($event['zone_name']) ?></div>
                    <span class="cal-room"><?= htmlspecialchars($event['room_name']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>
