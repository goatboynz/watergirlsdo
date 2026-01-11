<?php
require_once 'auth.php';
require_once 'init_db.php';

$pdo = initializeDatabase();

// Get logs from the last 24 hours
$stmt = $pdo->prepare("SELECT l.*, z.name as zone_name, r.name as room_name 
                      FROM IrrigationLogs l
                      JOIN Zones z ON l.zone_id = z.id 
                      JOIN Rooms r ON z.room_id = r.id 
                      WHERE l.start_time >= datetime('now', '-24 hours')
                      ORDER BY l.start_time DESC");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Irrigation History - Waterd Girls Do</title>
    <link rel="stylesheet" href="css/waterd.css">
    <style>
        .history-table { width: 100%; border-collapse: separate; border-spacing: 0 0.5rem; }
        .history-table th { text-align: left; color: var(--text-dim); padding: 1rem; font-size: 0.8rem; text-transform: uppercase; }
        .history-row { background: var(--card-bg); border-radius: 12px; transition: transform 0.2s; }
        .history-row td { padding: 1.25rem 1rem; }
        .history-row td:first-child { border-radius: 12px 0 0 12px; }
        .history-row td:last-child { border-radius: 0 12px 12px 0; }
        .history-row:hover { transform: scale(1.01); background: rgba(255,255,255,0.08); }
    </style>
</head>
<body>
    <header>
        <div class="logo-text">WATERD GIRLS DO</div>
        <nav>
            <ul>
                <li><a href="dashboard.php">Dashboard</a></li>
                <li><a href="irrigation.php">Rooms</a></li>
                <li><a href="calendar.php">Calendar</a></li>
                <li><a href="history.php" class="active">History</a></li>
            </ul>
        </nav>
    </header>

    <main class="container">
        <h1>Irrigation History</h1>
        <p style="color:var(--text-dim);">Detailed log of all irrigation events completed in the last 24 hours.</p>

        <table class="history-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Room / Zone</th>
                    <th>Phase</th>
                    <th>Duration</th>
                    <th>Volume</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr class="history-row">
                    <td>
                        <strong style="color:var(--emerald);"><?= date('H:i', strtotime($log['start_time'])) ?></strong><br>
                        <small style="color:var(--text-dim);"><?= date('M j', strtotime($log['start_time'])) ?></small>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($log['zone_name']) ?></strong><br>
                        <small style="color:var(--text-dim);"><?= htmlspecialchars($log['room_name']) ?></small>
                    </td>
                    <td>
                        <span class="badge badge-<?= strtolower($log['event_type']) ?>"><?= $log['event_type'] ?></span>
                    </td>
                    <td><?= floor($log['duration_seconds']/60) ?>m <?= $log['duration_seconds']%60 ?>s</td>
                    <td>
                        <strong style="color:var(--gold);"><?= number_format($log['volume_ml'], 1) ?> mL</strong>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="5" style="text-align:center; padding:3rem; color:var(--text-dim);">No irrigation events logged in the last 24 hours.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </main>
</body>
</html>
