<?php
require_once 'auth.php';
require_once 'init_db.php';
require_once 'ha_api.php';

$pdo = initializeDatabase();

// Handle Form Submissions (Add Room, Delete Room, Zone Management, Event Management)
// These are redirected from room.php or handled here for simplicity
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'add_room':
                $stmt = $pdo->prepare("INSERT INTO Rooms (name, description, lights_on, lights_off) VALUES (?, ?, ?, ?)");
                $stmt->execute([$_POST['name'], $_POST['description'] ?? '', '08:00', '20:00']);
                header("Location: irrigation.php");
                exit;
            case 'delete_room': $stmt = $pdo->prepare("DELETE FROM Rooms WHERE id = ?"); $stmt->execute([$_POST['id']]); break;
            case 'add_zone':
                $stmt = $pdo->prepare("INSERT INTO Zones (room_id, name, pump_entity_id, solenoid_entity_id, plants_count, drippers_per_plant, dripper_flow_rate) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$_POST['room_id'], $_POST['name'], $_POST['pump_id'], $_POST['solenoid_id'] ?: null, $_POST['plants_count'], $_POST['drippers_per_plant'], $_POST['flow_rate']]);
                header("Location: room.php?id=" . $_POST['room_id']);
                exit;
            case 'edit_zone':
                $stmt = $pdo->prepare("UPDATE Zones SET name = ?, pump_entity_id = ?, solenoid_entity_id = ?, plants_count = ?, drippers_per_plant = ?, dripper_flow_rate = ? WHERE id = ?");
                $stmt->execute([$_POST['name'], $_POST['pump_id'], $_POST['solenoid_id'] ?: null, $_POST['plants_count'], $_POST['drippers_per_plant'], $_POST['flow_rate'], $_POST['id']]);
                header("Location: room.php?id=" . $_POST['room_id']);
                exit;
            case 'delete_event': 
                $stmt = $pdo->prepare("DELETE FROM IrrigationEvents WHERE id = ?"); 
                $stmt->execute([$_POST['id']]); 
                header("Location: " . $_SERVER['HTTP_REFERER']);
                exit;
            case 'add_event':
                $stmt = $pdo->prepare("INSERT INTO IrrigationEvents (zone_id, event_type, start_time, duration_seconds) VALUES (?, ?, ?, ?)");
                $duration = (intval($_POST['mins'] ?? 0) * 60) + intval($_POST['secs'] ?? 0);
                $stmt->execute([$_POST['zone_id'], $_POST['type'], $_POST['start_time'], $duration]);
                header("Location: " . $_SERVER['HTTP_REFERER']);
                exit;
            case 'edit_event':
                $stmt = $pdo->prepare("UPDATE IrrigationEvents SET event_type = ?, start_time = ?, duration_seconds = ? WHERE id = ?");
                $duration = (intval($_POST['mins'] ?? 0) * 60) + intval($_POST['secs'] ?? 0);
                $stmt->execute([$_POST['type'], $_POST['start_time'], $duration, $_POST['id']]);
                header("Location: " . $_SERVER['HTTP_REFERER']);
                exit;
            case 'shot_engine':
                $zone_id = $_POST['zone_id'];
                if (isset($_POST['clear_existing'])) $pdo->prepare("DELETE FROM IrrigationEvents WHERE zone_id = ?")->execute([$zone_id]);
                $stmt = $pdo->prepare("INSERT INTO IrrigationEvents (zone_id, event_type, start_time, duration_seconds) VALUES (?, ?, ?, ?)");
                $timeObj = new DateTime($_POST['p1_start']);
                for ($i=0; $i<intval($_POST['p1_count']); $i++) {
                    $stmt->execute([$zone_id, 'P1', $timeObj->format('H:i'), (intval($_POST['p1_mins']) * 60) + intval($_POST['p1_secs'])]);
                    $timeObj->modify("+{$_POST['p1_interval']} minutes");
                }
                if (isset($_POST['p2_enabled'])) {
                    for ($i=0; $i<intval($_POST['p2_count']); $i++) {
                        $stmt->execute([$zone_id, 'P2', $timeObj->format('H:i'), (intval($_POST['p2_mins']) * 60) + intval($_POST['p2_secs'])]);
                        $timeObj->modify("+{$_POST['p2_interval']} minutes");
                    }
                }
                header("Location: " . $_SERVER['HTTP_REFERER']);
                exit;
        }
    } catch (Exception $e) { $error = $e->getMessage(); }
}

// Quick Global Actions
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'prime' && isset($_GET['zone_id'])) {
        $stmt = $pdo->prepare("SELECT pump_entity_id, solenoid_entity_id FROM Zones WHERE id = ?");
        $stmt->execute([$_GET['zone_id']]);
        $z = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($z) {
            if ($z['solenoid_entity_id']) ha_set_state($z['solenoid_entity_id'], 'on');
            ha_set_state($z['pump_entity_id'], 'on');
            // We can't really sleep here blockingly easily in a web request without UI hanging, 
            // but for 5s prime it's okay for testing.
            usleep(5000000); 
            ha_set_state($z['pump_entity_id'], 'off');
            if ($z['solenoid_entity_id']) ha_set_state($z['solenoid_entity_id'], 'off');
        }
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
}

$rooms = $pdo->query("SELECT * FROM Rooms ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$ha_entities = ha_get_entities();
$entities_map = [];
foreach ($ha_entities as $e) { 
    $val = $e['state'] ?? '--';
    if (is_numeric($val)) $val = round(floatval($val), 1);
    $entities_map[$e['entity_id']] = $val; 
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rooms - Waterd Girls Do</title>
    <link rel="stylesheet" href="css/waterd.css">
    <style>
        .room-card { background: var(--card-bg); border-radius: 24px; padding: 2rem; border: 1px solid rgba(255,255,255,0.05); transition: all 0.3s; cursor: pointer; position: relative; }
        .room-card:hover { transform: translateY(-5px); border-color: var(--emerald); box-shadow: 0 10px 40px rgba(0,0,0,0.4); }
        .room-stats-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-top: 1.5rem; }
        .room-stat-mini { background: rgba(0,0,0,0.2); padding: 0.8rem; border-radius: 12px; text-align: center; }
        .room-stat-mini small { display: block; font-size: 0.6rem; color: var(--text-dim); text-transform: uppercase; }
        .room-stat-mini span { font-weight: 700; font-size: 1rem; }
    </style>
</head>
<body>
    <header>
        <div class="logo-text">WATERD GIRLS DO</div>
        <nav>
            <ul>
                <li><a href="dashboard.php">Dashboard</a></li>
                <li><a href="irrigation.php" class="active">Rooms</a></li>
                <li><a href="calendar.php">Calendar</a></li>
                <li><a href="history.php">History</a></li>
            </ul>
        </nav>
    </header>

    <main class="container">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 3rem;">
            <div>
                <h1>Facility Rooms</h1>
                <p style="color:var(--text-dim);">Select a room to monitor live data and manage irrigation strategies.</p>
            </div>
            <button class="btn btn-primary" onclick="showModal('roomModal')">+ New Room</button>
        </div>

        <div class="dashboard-grid">
            <?php foreach($rooms as $r): 
                $temp = $entities_map[$r['temp_sensor_id']] ?? '--';
                $hum = $entities_map[$r['humidity_sensor_id']] ?? '--';
                
                // Calculate VPD for summary
                $vpd = '--';
                if (is_numeric($temp) && is_numeric($hum)) {
                    $vp_sat = 0.61078 * exp((17.27 * (float)$temp) / ((float)$temp + 237.3));
                    $vpd = round($vp_sat * (1 - (float)$hum / 100), 2);
                }
            ?>
            <article class="room-card" onclick="location.href='room.php?id=<?= $r['id'] ?>'">
                <h3 style="margin:0; font-size:1.5rem;"><?= htmlspecialchars($r['name']) ?></h3>
                <p style="color:var(--text-dim); font-size:0.9rem; margin-top:0.5rem;"><?= htmlspecialchars($r['description']) ?></p>
                
                <div class="room-stats-summary">
                    <div class="room-stat-mini"><small>Temp</small><span><?= $temp ?>°C</span></div>
                    <div class="room-stat-mini"><small>RH</small><span><?= $hum ?>%</span></div>
                    <div class="room-stat-mini"><small>VPD</small><span style="color:var(--emerald);"><?= $vpd ?></span></div>
                </div>

                <div style="margin-top:1.5rem; display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:0.7rem; color:var(--text-dim); text-transform:uppercase; letter-spacing:1px;">Click to Enter</span>
                    <form method="POST" onsubmit="event.stopPropagation(); return confirm('Delete Room?');" style="margin:0;">
                        <input type="hidden" name="action" value="delete_room">
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <button type="submit" style="background:none; border:none; color:var(--danger); cursor:pointer; font-size:0.8rem;">Delete</button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
            <?php if(empty($rooms)): ?>
            <div style="grid-column: span 12; text-align:center; padding:5rem; background:rgba(255,255,255,0.02); border-radius:32px; border:2px dashed rgba(255,255,255,0.05);">
                <h2 style="color:var(--text-dim);">No rooms configured.</h2>
                <button class="btn btn-primary" onclick="showModal('roomModal')" style="margin-top:1rem;">Add your first room</button>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <div id="roomModal" class="modal-backdrop">
        <div class="modal">
            <h2>Add New Room</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_room">
                <label>Room Name</label><input type="text" name="name" placeholder="e.g. Flower Room A" required>
                <label>Description</label><input type="text" name="description" placeholder="Short description...">
                <div class="grid-2" style="margin-top:1.5rem;">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('roomModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Room</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showModal(id) { document.getElementById(id).style.display='flex'; }
        function hideModal(id) { document.getElementById(id).style.display='none'; }
    </script>
</body>
</html>
