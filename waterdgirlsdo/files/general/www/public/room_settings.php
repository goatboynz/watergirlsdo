<?php
require_once 'auth.php';
require_once 'init_db.php';
require_once 'ha_api.php';

$pdo = initializeDatabase();
$roomId = $_GET['id'] ?? null;

if (!$roomId) {
    header("Location: irrigation.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM Rooms WHERE id = ?");
$stmt->execute([$roomId]);
$room = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$room) {
    header("Location: irrigation.php");
    exit;
}

$ha_entities = ha_get_entities();
$switches = array_filter($ha_entities, function($e) { return in_array(explode('.', $e['entity_id'])[0], ['switch', 'light', 'outlet']); });
$sensors = array_filter($ha_entities, function($e) { return explode('.', $e['entity_id'])[0] === 'sensor'; });

// Sort sensors by name
usort($sensors, function($a, $b) { return strcmp($a['attributes']['friendly_name'] ?? $a['entity_id'], $b['attributes']['friendly_name'] ?? $b['entity_id']); });

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_room') {
        $sql = "UPDATE Rooms SET 
                name = ?, description = ?, lights_on = ?, lights_off = ?, 
                temp_sensor_id = ?, humidity_sensor_id = ?, 
                moisture_sensor_1 = ?, moisture_sensor_2 = ?, moisture_sensor_3 = ?, moisture_sensor_4 = ?, moisture_sensor_5 = ?, 
                ec_sensor_1 = ?, ec_sensor_2 = ?, ec_sensor_3 = ?, ec_sensor_4 = ?, ec_sensor_5 = ? 
                WHERE id = ?";
        $params = [
            $_POST['name'], $_POST['description'], $_POST['lights_on'], $_POST['lights_off'],
            $_POST['temp_id'] ?: null, $_POST['hum_id'] ?: null,
            $_POST['m1'] ?: null, $_POST['m2'] ?: null, $_POST['m3'] ?: null, $_POST['m4'] ?: null, $_POST['m5'] ?: null,
            $_POST['e1'] ?: null, $_POST['e2'] ?: null, $_POST['e3'] ?: null, $_POST['e4'] ?: null, $_POST['e5'] ?: null,
            $roomId
        ];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        header("Location: room.php?id=$roomId");
        exit;
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Room Settings - <?= htmlspecialchars($room['name']) ?></title>
    <link rel="stylesheet" href="css/waterd.css">
    <style>
        .settings-section { background: var(--card-bg); border-radius: 20px; padding: 2rem; border: 1px solid rgba(255,255,255,0.05); margin-bottom: 2rem; }
        .settings-section h2 { margin-top: 0; margin-bottom: 1.5rem; color: var(--emerald); font-size: 1.2rem; display: flex; align-items: center; gap: 0.5rem; }
        .sensor-row { display: grid; grid-template-columns: 100px 1fr; align-items: center; gap: 1rem; margin-bottom: 0.5rem; }
        .sensor-row span { font-size: 0.8rem; color: var(--text-dim); }
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
        <div style="margin-bottom: 2rem;">
            <a href="room.php?id=<?= $roomId ?>" style="color:var(--text-dim); text-decoration:none; font-size:0.9rem;">← Back to Room</a>
            <h1 style="margin-top:0.5rem;">Room Settings: <?= htmlspecialchars($room['name']) ?></h1>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="update_room">
            
            <div class="settings-section">
                <h2>General Configuration</h2>
                <div class="grid-2">
                    <label>Room Name<input type="text" name="name" value="<?= htmlspecialchars($room['name']) ?>" required></label>
                    <label>Description<input type="text" name="description" value="<?= htmlspecialchars($room['description']) ?>"></label>
                </div>
                <div class="grid-2">
                    <label>Lights On<input type="time" name="lights_on" value="<?= $room['lights_on'] ?>"></label>
                    <label>Lights Off<input type="time" name="lights_off" value="<?= $room['lights_off'] ?>"></label>
                </div>
            </div>

            <div class="settings-section">
                <h2>Environmental Sensors</h2>
                <div class="grid-2">
                    <label>Temperature Sensor
                        <select name="temp_id">
                            <option value="">None</option>
                            <?php foreach($sensors as $s): ?>
                                <option value="<?= $s['entity_id'] ?>" <?= $room['temp_sensor_id'] == $s['entity_id'] ? 'selected' : '' ?>><?= $s['attributes']['friendly_name'] ?? $s['entity_id'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Humidity Sensor
                        <select name="hum_id">
                            <option value="">None</option>
                            <?php foreach($sensors as $s): ?>
                                <option value="<?= $s['entity_id'] ?>" <?= $room['humidity_sensor_id'] == $s['entity_id'] ? 'selected' : '' ?>><?= $s['attributes']['friendly_name'] ?? $s['entity_id'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
            </div>

            <div class="settings-section">
                <h2>Substrate Sensors (Up to 5)</h2>
                <p style="color:var(--text-dim); font-size:0.8rem; margin-bottom:1.5rem;">These sensors will be graphed together on the room page for crop comparison.</p>
                
                <div class="grid-2">
                    <div>
                        <h3 style="font-size:0.9rem; margin-bottom:1rem;">Moisture / VWC (%)</h3>
                        <?php for($i=1; $i<=5; $i++): ?>
                        <div class="sensor-row">
                            <span>Probe <?= $i ?></span>
                            <select name="m<?= $i ?>">
                                <option value="">None</option>
                                <?php foreach($sensors as $s): ?>
                                    <option value="<?= $s['entity_id'] ?>" <?= $room["moisture_sensor_$i"] == $s['entity_id'] ? 'selected' : '' ?>><?= $s['attributes']['friendly_name'] ?? $s['entity_id'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <div>
                        <h3 style="font-size:0.9rem; margin-bottom:1rem;">Electrical Conductivity (EC)</h3>
                        <?php for($i=1; $i<=5; $i++): ?>
                        <div class="sensor-row">
                            <span>Probe <?= $i ?></span>
                            <select name="e<?= $i ?>">
                                <option value="">None</option>
                                <?php foreach($sensors as $s): ?>
                                    <option value="<?= $s['entity_id'] ?>" <?= $room["ec_sensor_$i"] == $s['entity_id'] ? 'selected' : '' ?>><?= $s['attributes']['friendly_name'] ?? $s['entity_id'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:1rem;">
                <a href="room.php?id=<?= $roomId ?>" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary" style="padding: 1rem 3rem;">Save Room Settings</button>
            </div>
        </form>
    </main>
</body>
</html>
