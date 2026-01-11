<?php
require_once 'auth.php';
require_once 'init_db.php';
require_once 'ha_api.php';

$pdo = initializeDatabase();

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'add_room':
                $stmt = $pdo->prepare("INSERT INTO Rooms (name, description, lights_on, lights_off, temp_sensor_id, humidity_sensor_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$_POST['name'], $_POST['description'], $_POST['lights_on'], $_POST['lights_off'], $_POST['temp_id'] ?: null, $_POST['hum_id'] ?: null]);
                break;
            case 'edit_room':
                $stmt = $pdo->prepare("UPDATE Rooms SET name = ?, description = ?, lights_on = ?, lights_off = ?, temp_sensor_id = ?, humidity_sensor_id = ? WHERE id = ?");
                $stmt->execute([$_POST['name'], $_POST['description'], $_POST['lights_on'], $_POST['lights_off'], $_POST['temp_id'] ?: null, $_POST['hum_id'] ?: null, $_POST['id']]);
                break;
            case 'delete_room':
                $stmt = $pdo->prepare("DELETE FROM Rooms WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                break;
            case 'add_zone':
                $stmt = $pdo->prepare("INSERT INTO Zones (room_id, name, pump_entity_id, solenoid_entity_id, plants_count, drippers_per_plant, dripper_flow_rate, moisture_sensor_id, ec_sensor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$_POST['room_id'], $_POST['name'], $_POST['pump_id'], $_POST['solenoid_id'] ?: null, $_POST['plants_count'], $_POST['drippers_per_plant'], $_POST['flow_rate'], $_POST['moisture_id'] ?: null, $_POST['ec_id'] ?: null]);
                break;
            case 'edit_zone':
                $stmt = $pdo->prepare("UPDATE Zones SET name = ?, pump_entity_id = ?, solenoid_entity_id = ?, plants_count = ?, drippers_per_plant = ?, dripper_flow_rate = ?, moisture_sensor_id = ?, ec_sensor_id = ? WHERE id = ?");
                $stmt->execute([$_POST['name'], $_POST['pump_id'], $_POST['solenoid_id'] ?: null, $_POST['plants_count'], $_POST['drippers_per_plant'], $_POST['flow_rate'], $_POST['moisture_id'] ?: null, $_POST['ec_id'] ?: null, $_POST['id']]);
                break;
            case 'delete_zone':
                $stmt = $pdo->prepare("DELETE FROM Zones WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                break;
            case 'add_event':
                $stmt = $pdo->prepare("INSERT INTO IrrigationEvents (zone_id, event_type, start_time, duration_seconds, days_of_week) VALUES (?, ?, ?, ?, ?)");
                $duration = (intval($_POST['mins']) * 60) + intval($_POST['secs']);
                $days = isset($_POST['days']) ? implode(',', $_POST['days']) : '1,2,3,4,5,6,7';
                $stmt->execute([$_POST['zone_id'], $_POST['type'], $_POST['start_time'], $duration, $days]);
                break;
            case 'shot_engine':
                $zone_id = $_POST['zone_id'];
                if (isset($_POST['clear_existing'])) {
                    $pdo->prepare("DELETE FROM IrrigationEvents WHERE zone_id = ?")->execute([$zone_id]);
                }
                $stmt = $pdo->prepare("INSERT INTO IrrigationEvents (zone_id, event_type, start_time, duration_seconds) VALUES (?, ?, ?, ?)");
                $timeObj = new DateTime($_POST['p1_start']);
                for ($i = 0; $i < intval($_POST['p1_count']); $i++) {
                    $stmt->execute([$zone_id, 'P1', $timeObj->format('H:i'), (intval($_POST['p1_mins']) * 60) + intval($_POST['p1_secs'])]);
                    $timeObj->modify("+{$_POST['p1_interval']} minutes");
                }
                if (isset($_POST['p2_enabled'])) {
                    for ($i = 0; $i < intval($_POST['p2_count']); $i++) {
                        $stmt->execute([$zone_id, 'P2', $timeObj->format('H:i'), (intval($_POST['p2_mins']) * 60) + intval($_POST['p2_secs'])]);
                        $timeObj->modify("+{$_POST['p2_interval']} minutes");
                    }
                }
                break;
            case 'edit_event':
                $duration = (intval($_POST['mins']) * 60) + intval($_POST['secs']);
                $stmt = $pdo->prepare("UPDATE IrrigationEvents SET event_type = ?, start_time = ?, duration_seconds = ? WHERE id = ?");
                $stmt->execute([$_POST['type'], $_POST['start_time'], $duration, $_POST['id']]);
                break;
            case 'delete_event':
                $stmt = $pdo->prepare("DELETE FROM IrrigationEvents WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                break;
            case 'toggle_event':
                $stmt = $pdo->prepare("UPDATE IrrigationEvents SET enabled = 1 - enabled WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                break;
            case 'prime':
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
                break;
        }
    } catch (Exception $e) { $error = $e->getMessage(); }
}

// Data Fetching
$rooms = $pdo->query("SELECT * FROM Rooms ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$ha_entities = ha_get_entities();
$switches = array_filter($ha_entities, function($e) { return in_array(explode('.', $e['entity_id'])[0], ['switch', 'light', 'outlet']); });
$sensors = array_filter($ha_entities, function($e) { return explode('.', $e['entity_id'])[0] === 'sensor'; });

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Waterd Girls Do - Rooms</title>
    <link rel="stylesheet" href="css/waterd.css">
    <style>
        .icon-btn { background: transparent; border: none; color: var(--text-dim); cursor: pointer; padding: 0.2rem; transition: color 0.3s; }
        .icon-btn:hover { color: var(--emerald); }
        .zone-metrics { background: rgba(0,0,0,0.4); padding: 0.8rem; border-radius: 12px; margin-top: 1rem; font-size: 0.85rem; border: 1px solid rgba(255,255,255,0.05); }
        .metric-row { display: flex; justify-content: space-between; margin-bottom: 0.3rem; }
        .strategy-block { background: rgba(255,255,255,0.05); padding: 1rem; border-radius: 15px; margin-bottom: 1rem; border: 1px solid rgba(255,255,255,0.1); }
        .strategy-block h3 { margin-top: 0; font-size: 1rem; color: var(--emerald); }
        .mod-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 0.5rem; }
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
            </ul>
        </nav>
    </header>

    <main class="container">
        <div class="room-header">
            <h1>Rooms & Strategic Control</h1>
            <button class="btn btn-primary" onclick="showRoomModal()">+ Add Room</button>
        </div>

        <?php foreach ($rooms as $room): ?>
        <section class="room-section card">
            <div class="room-header">
                <div>
                    <h2 style="margin:0; display:flex; align-items:center; gap:0.5rem;">
                        <?= htmlspecialchars($room['name']) ?>
                        <button class="icon-btn" style="font-size:0.9rem;" onclick='showRoomModal(<?= json_encode($room) ?>)'>✎</button>
                    </h2>
                    <small style="color:var(--text-dim)">Lights: <?= $room['lights_on'] ?> - <?= $room['lights_off'] ?></small>
                </div>
                <div style="display:flex; gap: 0.5rem;">
                    <button class="btn btn-secondary" onclick="showZoneModal(<?= $room['id'] ?>, '<?= addslashes($room['name']) ?>')">+ Add Zone</button>
                    <form method="POST" onsubmit="return confirm('Delete room?')">
                        <input type="hidden" name="action" value="delete_room"><input type="hidden" name="id" value="<?= $room['id'] ?>">
                        <button class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>

            <div class="zone-grid">
                <?php
                $zs = $pdo->prepare("SELECT * FROM Zones WHERE room_id = ?"); $zs->execute([$room['id']]);
                foreach ($zs->fetchAll(PDO::FETCH_ASSOC) as $zone): ?>
                <div class="zone-card">
                    <div style="display:flex; justify-content:space-between; align-items:start;">
                        <strong style="display:flex; align-items:center; gap:0.3rem;">
                            <?= htmlspecialchars($zone['name']) ?>
                            <button class="icon-btn" style="font-size:0.8rem;" onclick='showZoneModal(<?= $room['id'] ?>, "", <?= json_encode($zone) ?>)'>✎</button>
                        </strong>
                        <form method="POST"><input type="hidden" name="action" value="prime"><input type="hidden" name="zone_id" value="<?= $zone['id'] ?>"><button class="btn-secondary" style="color:var(--gold); border:1px solid var(--gold); padding:0.1rem 0.4rem; font-size:0.6rem; cursor:pointer;">PRIME</button></form>
                    </div>
                    
                    <div class="zone-metrics">
                        <div class="metric-row"><span>Plants/Feed:</span> <strong><?= $zone['plants_count'] ?> @ <?= $zone['drippers_per_plant'] ?>x</strong></div>
                        <div class="metric-row"><span>Sensor:</span> <small><?= $zone['moisture_sensor_id'] ? 'Linked' : 'None' ?></small></div>
                    </div>

                    <div class="event-list">
                        <?php
                        $es = $pdo->prepare("SELECT * FROM IrrigationEvents WHERE zone_id = ? ORDER BY start_time ASC"); $es->execute([$zone['id']]);
                        foreach ($es->fetchAll(PDO::FETCH_ASSOC) as $event): ?>
                        <div class="event-item">
                            <span>
                                <form method="POST" style="display:inline;"><input type="hidden" name="action" value="toggle_event"><input type="hidden" name="id" value="<?= $event['id'] ?>"><button type="submit" class="badge" style="background: <?= $event['enabled'] ? 'var(--emerald)' : 'var(--danger)' ?>; border:none; cursor:pointer; font-size:0.5rem;"><?= $event['enabled'] ? 'ON' : 'OFF' ?></button></form>
                                <span class="badge badge-<?= strtolower($event['event_type']) ?>"><?= $event['event_type'] ?></span>
                                <strong><?= $event['start_time'] ?></strong>
                            </span>
                            <small><?= floor($event['duration_seconds']/60) ?>m <?= $event['duration_seconds']%60 ?>s</small>
                        </div>
                        <?php endforeach; ?>
                        <button class="btn btn-primary" style="width:100%; padding:0.3rem; margin-top:0.5rem; font-size:0.75rem;" onclick="showShotEngineModal(<?= $zone['id'] ?>)">Shot Engine</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
    </main>

    <!-- Modals -->
    <div id="roomModal" class="modal-backdrop">
        <div class="modal">
            <h2 id="roomTitle">Add Room</h2>
            <form method="POST">
                <input type="hidden" name="action" id="roomAction" value="add_room"><input type="hidden" name="id" id="roomId">
                <input type="text" name="name" id="roomName" placeholder="Name" required>
                <div class="grid-2">
                    <label><small>Lights On</small><input type="time" name="lights_on" id="roomOn" value="08:00"></label>
                    <label><small>Lights Off</small><input type="time" name="lights_off" id="roomOff" value="20:00"></label>
                </div>
                <div class="grid-2">
                    <label><small>Temp Sensor</small><select name="temp_id" id="roomTemp"><option value="">None</option><?php foreach($sensors as $s){ echo "<option value='{$s['entity_id']}'>{$s['attributes']['friendly_name']}</option>"; } ?></select></label>
                    <label><small>Humidity Sensor</small><select name="hum_id" id="roomHum"><option value="">None</option><?php foreach($sensors as $s){ echo "<option value='{$s['entity_id']}'>{$s['attributes']['friendly_name']}</option>"; } ?></select></label>
                </div>
                <div class="grid-2"><button type="button" class="btn btn-secondary" onclick="hideModal('roomModal')">Cancel</button><button type="submit" class="btn btn-primary">Save Room</button></div>
            </form>
        </div>
    </div>

    <div id="zoneModal" class="modal-backdrop">
        <div class="modal">
            <h2>Zone Setup</h2>
            <form method="POST">
                <input type="hidden" name="action" id="zoneAction" value="add_zone"><input type="hidden" name="id" id="zoneId"><input type="hidden" name="room_id" id="zRoomId">
                <input type="text" name="name" id="zoneName" placeholder="Zone Name" required>
                <div class="grid-2">
                    <label><small>Pump</small><select name="pump_id" id="zPump"><?php foreach($switches as $sw){ echo "<option value='{$sw['entity_id']}'>{$sw['attributes']['friendly_name']}</option>"; } ?></select></label>
                    <label><small>Solenoid</small><select name="solenoid_id" id="zSol"><option value="">None</option><?php foreach($switches as $sw){ echo "<option value='{$sw['entity_id']}'>{$sw['attributes']['friendly_name']}</option>"; } ?></select></label>
                </div>
                <div class="mod-row">
                    <label><small>Plants</small><input type="number" name="plants_count" id="zPlants" value="1"></label>
                    <label><small>Drippers/P</small><input type="number" name="drippers_per_plant" id="zDripP" value="1"></label>
                    <label><small>mL/h</small><input type="number" name="flow_rate" id="zFlow" value="2000"></label>
                </div>
                <div class="grid-2">
                    <label><small>Moisture Sensor</small><select name="moisture_id" id="zMoist"><option value="">None</option><?php foreach($sensors as $s){ echo "<option value='{$s['entity_id']}'>{$s['attributes']['friendly_name']}</option>"; } ?></select></label>
                    <label><small>EC Sensor</small><select name="ec_id" id="zEc"><option value="">None</option><?php foreach($sensors as $s){ echo "<option value='{$s['entity_id']}'>{$s['attributes']['friendly_name']}</option>"; } ?></select></label>
                </div>
                <div class="grid-2"><button type="button" class="btn btn-secondary" onclick="hideModal('zoneModal')">Cancel</button><button type="submit" class="btn btn-primary">Save Zone</button></div>
            </form>
        </div>
    </div>

    <div id="shotEngineModal" class="modal-backdrop">
        <div class="modal" style="max-width:600px;">
            <h2>Strategy Builder</h2>
            <form method="POST">
                <input type="hidden" name="action" value="shot_engine"><input type="hidden" name="zone_id" id="seZoneId">
                <div class="grid-2">
                    <label><small>P1 Start</small><input type="time" name="p1_start" value="08:00"></label>
                    <label><small>P1 Count</small><input type="number" name="p1_count" value="5"></label>
                </div>
                <div class="grid-2">
                    <label><small>Interval</small><input type="number" name="p1_interval" value="30"></label>
                    <label><small>Duration</small><div style="display:flex;"><input type="number" name="p1_mins" value="0">m<input type="number" name="p1_secs" value="45">s</div></label>
                </div>
                <hr style="opacity:0.1; margin:1rem 0;">
                <label style="display:flex; align-items:center; gap:0.5rem;"><input type="checkbox" name="p2_enabled" checked> Enable P2</label>
                <div class="grid-2">
                    <label><small>P2 Count</small><input type="number" name="p2_count" value="10"></label>
                    <label><small>Interval</small><input type="number" name="p2_interval" value="60"></label>
                </div>
                <label><small>P2 Duration</small><div style="display:flex;"><input type="number" name="p2_mins" value="0">m<input type="number" name="p2_secs" value="30">s</div></label>
                <div style="margin:1rem 0;"><label><input type="checkbox" name="clear_existing" checked> Clear zone events</label></div>
                <div class="grid-2"><button type="button" class="btn btn-secondary" onclick="hideModal('shotEngineModal')">Cancel</button><button type="submit" class="btn btn-primary">Build Strategy</button></div>
            </form>
        </div>
    </div>

    <script>
        function showModal(id) { document.getElementById(id).style.display='flex'; }
        function hideModal(id) { document.getElementById(id).style.display='none'; }
        function showRoomModal(d=null) {
            let pref = d ? 'edit_room' : 'add_room'; document.getElementById('roomAction').value=pref;
            document.getElementById('roomId').value = d?d.id:'';
            document.getElementById('roomName').value = d?d.name:'';
            document.getElementById('roomOn').value = d?d.lights_on:'08:00';
            document.getElementById('roomOff').value = d?d.lights_off:'20:00';
            document.getElementById('roomTemp').value = d?d.temp_sensor_id:'';
            document.getElementById('roomHum').value = d?d.humidity_sensor_id:'';
            showModal('roomModal');
        }
        function showZoneModal(rid, rname, d=null) {
            document.getElementById('zRoomId').value=rid;
            let pref = d ? 'edit_zone' : 'add_zone'; document.getElementById('zoneAction').value=pref;
            document.getElementById('zoneId').value = d?d.id:'';
            document.getElementById('zoneName').value = d?d.name:'';
            document.getElementById('zPump').value = d?d.pump_entity_id:'';
            document.getElementById('zSol').value = d?d.solenoid_entity_id:'';
            document.getElementById('zPlants').value = d?d.plants_count:1;
            document.getElementById('zDripP').value = d?d.drippers_per_plant:1;
            document.getElementById('zFlow').value = d?d.dripper_flow_rate:2000;
            document.getElementById('zMoist').value = d?d.moisture_sensor_id:'';
            document.getElementById('zEc').value = d?d.ec_sensor_id:'';
            showModal('zoneModal');
        }
        function showShotEngineModal(zid) { document.getElementById('seZoneId').value=zid; showModal('shotEngineModal'); }
    </script>
</body>
</html>
