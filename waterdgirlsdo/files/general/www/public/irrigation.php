<?php
require_once 'auth.php';
require_once 'init_db.php';
require_once 'ha_api.php';

$pdo = initializeDatabase();

function calculateVPD($temp, $humidity) {
    if ($temp === null || $humidity === null || $temp === '' || $humidity === '') return null;
    $vp_sat = 0.61078 * exp((17.27 * floatval($temp)) / (floatval($temp) + 237.3));
    $vpd = $vp_sat * (1 - floatval($humidity) / 100);
    return round($vpd, 2);
}

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
                $stmt = $pdo->prepare("INSERT INTO IrrigationEvents (zone_id, event_type, start_time, duration_seconds) VALUES (?, ?, ?, ?)");
                $duration = (intval($_POST['mins']) * 60) + intval($_POST['secs']);
                $stmt->execute([$_POST['zone_id'], $_POST['type'], $_POST['start_time'], $duration]);
                break;
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
$entities_map = [];
foreach ($ha_entities as $e) { $entities_map[$e['entity_id']] = $e['state']; }

$switches = array_filter($ha_entities, function($e) { return in_array(explode('.', $e['entity_id'])[0], ['switch', 'light', 'outlet']); });
$sensors = array_filter($ha_entities, function($e) { return explode('.', $e['entity_id'])[0] === 'sensor'; });

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rooms - Waterd Girls Do</title>
    <link rel="stylesheet" href="css/waterd.css">
    <style>
        .room-card { position: relative; margin-bottom: 3rem; background: rgba(255,255,255,0.02); border-radius: 30px; border: 1px solid rgba(255,255,255,0.05); padding: 2rem; }
        .room-meta-float { display: flex; gap: 1rem; margin-top: 1rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1rem; }
        .meta-pill { background: rgba(255,255,255,0.05); padding: 0.5rem 1rem; border-radius: 12px; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem; }
        .meta-pill strong { color: var(--emerald); }
        .zone-sensor-row { display: flex; gap: 0.5rem; margin-top: 0.8rem; }
        .zone-sensor-pill { flex:1; background: rgba(0,0,0,0.3); padding: 0.5rem; border-radius: 10px; text-align: center; border: 1px solid rgba(255,255,255,0.03); }
        .zone-sensor-pill small { display: block; filter: brightness(0.7); font-size: 0.65rem; text-transform: uppercase; }
        .zone-sensor-pill span { font-weight: 700; font-size: 0.9rem; }
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
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 2rem;">
            <div>
                <h1 style="margin:0;">Facility Configuration</h1>
                <p style="color:var(--text-dim);">Manage your rooms, zones, and strategic feed plans.</p>
            </div>
            <button class="btn btn-primary" onclick="showRoomModal()">+ New Room</button>
        </div>

        <?php foreach ($rooms as $room): 
            $temp = $entities_map[$room['temp_sensor_id']] ?? null;
            $hum = $entities_map[$room['humidity_sensor_id']] ?? null;
            $vpd = calculateVPD($temp, $hum);
        ?>
        <div class="room-card">
            <div style="display:flex; justify-content:space-between; align-items:start;">
                <div>
                    <h2 style="margin:0; font-size:1.8rem; color:var(--text-main); display:flex; align-items:center; gap:0.5rem;">
                        <?= htmlspecialchars($room['name']) ?>
                        <button class="icon-btn" onclick='showRoomModal(<?= json_encode($room) ?>)' style="color:var(--text-dim);">✎</button>
                    </h2>
                    <p style="color:var(--text-dim); margin:0.3rem 0;"><?= htmlspecialchars($room['description']) ?></p>
                </div>
                <div style="display:flex; gap:0.8rem;">
                    <button class="btn btn-secondary" onclick="showZoneModal(<?= $room['id'] ?>, '<?= addslashes($room['name']) ?>')">+ Add Zone</button>
                    <form method="POST" onsubmit="return confirm('Delete this room?')">
                        <input type="hidden" name="action" value="delete_room"><input type="hidden" name="id" value="<?= $room['id'] ?>">
                        <button class="btn btn-secondary" style="color:var(--danger); border-color:rgba(231, 76, 60, 0.2);">Delete</button>
                    </form>
                </div>
            </div>

            <div class="room-meta-float">
                <div class="meta-pill">🌡️ <strong><?= $temp ?: '--' ?>°C</strong></div>
                <div class="meta-pill">💧 <strong><?= $hum ?: '--' ?>%</strong></div>
                <div class="meta-pill">🌬️ <strong><?= $vpd ?: '--' ?></strong> <small>VPD</small></div>
                <div class="meta-pill">💡 <strong><?= $room['lights_on'] ?> - <?= $room['lights_off'] ?></strong></div>
            </div>

            <div class="zone-grid" style="margin-top:2rem;">
                <?php
                if ($room): // Check if $room is set to prevent errors
                    $zs = $pdo->prepare("SELECT * FROM Zones WHERE room_id = ?"); 
                    $zs->execute([$room['id']]);
                    foreach ($zs->fetchAll(PDO::FETCH_ASSOC) as $zone): 
                        $vwc = $entities_map[$zone['moisture_sensor_id']] ?? null;
                        $ec = $entities_map[$zone['ec_sensor_id']] ?? null;
                ?>
                <div class="zone-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                        <h3 style="margin:0; font-size:1.1rem; flex:1; display:flex; align-items:center; gap:0.4rem;">
                            <?= htmlspecialchars($zone['name']) ?>
                            <button class="icon-btn" onclick='showZoneModal(<?= $room['id'] ?>, "", <?= json_encode($zone) ?>)' style="font-size:0.8rem;">✎</button>
                        </h3>
                        <form method="POST"><input type="hidden" name="action" value="prime"><input type="hidden" name="zone_id" value="<?= $zone['id'] ?>"><button class="btn btn-secondary" style="padding:0.3rem 0.6rem; font-size:0.7rem; color:var(--gold); border-color:var(--gold);">PRIME</button></form>
                    </div>

                    <div class="zone-sensor-row">
                        <div class="zone-sensor-pill">
                            <small>Moisture</small>
                            <span><?= $vwc ? $vwc.'%' : '--' ?></span>
                        </div>
                        <div class="zone-sensor-pill">
                            <small>EC</small>
                            <span><?= $ec ?: '--' ?></span>
                        </div>
                    </div>

                    <div class="event-list" style="margin-top:1.5rem;">
                        <?php
                        $es = $pdo->prepare("SELECT * FROM IrrigationEvents WHERE zone_id = ? ORDER BY start_time ASC"); $es->execute([$zone['id']]);
                        $evArr = $es->fetchAll(PDO::FETCH_ASSOC);
                        if(empty($evArr)): ?><p style="text-align:center; font-size:0.8rem; color:var(--text-dim); py-2; opacity:0.5;">No strategy active</p><?php endif;
                        foreach ($evArr as $event): ?>
                        <div class="event-item">
                            <div style="display:flex; align-items:center; gap:0.6rem;">
                                <form method="POST"><input type="hidden" name="action" value="toggle_event"><input type="hidden" name="id" value="<?= $event['id'] ?>"><button class="badge" style="background:<?= $event['enabled'] ? 'var(--emerald)' : 'var(--text-dim)'?>; border:none; cursor:pointer; color:black; font-size:0.5rem;"><?= $event['enabled'] ? 'ON' : 'OFF' ?></button></form>
                                <span class="badge badge-<?= strtolower($event['event_type']) ?>"><?= $event['event_type'] ?></span>
                                <strong style="font-size:1rem;"><?= $event['start_time'] ?></strong>
                            </div>
                            <span style="color:var(--text-dim);"><?= floor($event['duration_seconds']/60) ?>m <?= $event['duration_seconds']%60 ?>s</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-primary" onclick="showShotEngineModal(<?= $zone['id'] ?>)" style="width:100%; margin-top:1rem; font-size:0.8rem;">Strategy Builder</button>
                </div>
                <?php endforeach; 
                endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </main>

    <!-- Modals (Simplified/Same IDs for JS compatibility) -->
    <div id="roomModal" class="modal-backdrop">
        <div class="modal">
            <h2 id="roomTitle">Add Room</h2>
            <form method="POST">
                <input type="hidden" name="action" id="roomAction" value="add_room"><input type="hidden" name="id" id="roomId">
                <label>Room Name</label><input type="text" name="name" id="roomName" placeholder="Dry Room, Flower A, etc" required>
                <div class="grid-2">
                    <label>Lights On</label><input type="time" name="lights_on" id="roomOn" value="08:00">
                    <label>Lights Off</label><input type="time" name="lights_off" id="roomOff" value="20:00">
                </div>
                <div class="grid-2">
                    <label>Temp Sensor</label><select name="temp_id" id="roomTemp"><option value="">None</option><?php foreach($sensors as $s){ echo "<option value='{$s['entity_id']}'>{$s['attributes']['friendly_name']}</option>"; } ?></select>
                    <label>Humidity Sensor</label><select name="hum_id" id="roomHum"><option value="">None</option><?php foreach($sensors as $s){ echo "<option value='{$s['entity_id']}'>{$s['attributes']['friendly_name']}</option>"; } ?></select>
                </div>
                <div class="grid-2"><button type="button" class="btn btn-secondary" onclick="hideModal('roomModal')">Cancel</button><button type="submit" class="btn btn-primary">Save Room</button></div>
            </form>
        </div>
    </div>

    <div id="zoneModal" class="modal-backdrop">
        <div class="modal">
            <h2>Zone Configuration</h2>
            <form method="POST">
                <input type="hidden" name="action" id="zoneAction" value="add_zone"><input type="hidden" name="id" id="zoneId"><input type="hidden" name="room_id" id="zRoomId">
                <label>Zone Name</label><input type="text" name="name" id="zoneName" placeholder="North Bench, etc" required>
                <div class="grid-2">
                    <label>Pump</label><select name="pump_id" id="zPump"><?php foreach($switches as $sw){ echo "<option value='{$sw['entity_id']}'>{$sw['attributes']['friendly_name']}</option>"; } ?></select>
                    <label>Solenoid</label><select name="solenoid_id" id="zSol"><option value="">None</option><?php foreach($switches as $sw){ echo "<option value='{$sw['entity_id']}'>{$sw['attributes']['friendly_name']}</option>"; } ?></select>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:0.5rem; margin-bottom:1rem;">
                    <label><small>Plants</small><input type="number" name="plants_count" id="zPlants" value="1"></label>
                    <label><small>Drippers/P</small><input type="number" name="drippers_per_plant" id="zDripP" value="1"></label>
                    <label><small>mL/h Flow</small><input type="number" name="flow_rate" id="zFlow" value="2000"></label>
                </div>
                <div class="grid-2">
                    <label>Moisture Sensor</label><select name="moisture_id" id="zMoist"><option value="">None</option><?php foreach($sensors as $s){ echo "<option value='{$s['entity_id']}'>{$s['attributes']['friendly_name']}</option>"; } ?></select>
                    <label>EC Sensor</label><select name="ec_id" id="zEc"><option value="">None</option><?php foreach($sensors as $s){ echo "<option value='{$s['entity_id']}'>{$s['attributes']['friendly_name']}</option>"; } ?></select>
                </div>
                <div class="grid-2"><button type="button" class="btn btn-secondary" onclick="hideModal('zoneModal')">Cancel</button><button type="submit" class="btn btn-primary">Save Zone</button></div>
            </form>
        </div>
    </div>

    <div id="shotEngineModal" class="modal-backdrop">
        <div class="modal" style="max-width:600px;">
            <h2>Daily Strategy Builder</h2>
            <form method="POST">
                <input type="hidden" name="action" value="shot_engine"><input type="hidden" name="zone_id" id="seZoneId">
                <div style="background:rgba(255,255,255,0.03); padding:1rem; border-radius:15px; margin-bottom:1rem;">
                    <h4 style="margin:0 0 1rem 0; color:var(--emerald);">Phase 1: Ramp Up</h4>
                    <div class="grid-2">
                        <label>Start Time</label><input type="time" name="p1_start" value="08:00">
                        <label>Shots</label><input type="number" name="p1_count" value="5">
                    </div>
                    <div class="grid-2">
                        <label>Interval (Min)</label><input type="number" name="p1_interval" value="30">
                        <label>Drip Duration</label><div style="display:flex;"><input type="number" name="p1_mins" value="0"><input type="number" name="p1_secs" value="45"></div>
                    </div>
                </div>
                <div style="background:rgba(255,255,255,0.03); padding:1rem; border-radius:15px; margin-bottom:1rem;">
                    <div style="display:flex; justify-content:space-between;"><h4 style="margin:0; color:var(--gold);">Phase 2: Maintenance</h4><input type="checkbox" name="p2_enabled" checked></div>
                    <div class="grid-2" style="margin-top:1rem;">
                        <label>Shots</label><input type="number" name="p2_count" value="10">
                        <label>Interval (Min)</label><input type="number" name="p2_interval" value="60">
                    </div>
                    <label>Drip Duration</label><div style="display:flex;"><input type="number" name="p2_mins" value="0"><input type="number" name="p2_secs" value="30"></div>
                </div>
                <label style="display:flex; align-items:center; gap:0.5rem;"><input type="checkbox" name="clear_existing" checked> Clear existing events</label>
                <div class="grid-2" style="margin-top:1rem;"><button type="button" class="btn btn-secondary" onclick="hideModal('shotEngineModal')">Cancel</button><button type="submit" class="btn btn-primary">Build Plan</button></div>
            </form>
        </div>
    </div>

    <script>
        function showModal(id) { document.getElementById(id).style.display='flex'; }
        function hideModal(id) { document.getElementById(id).style.display='none'; }
        
        function showRoomModal(d=null) {
            document.getElementById('roomAction').value = d ? 'edit_room' : 'add_room';
            document.getElementById('roomId').value = d?d.id:'';
            document.getElementById('roomName').value = d?d.name:'';
            document.getElementById('roomOn').value = d?d.lights_on:'08:00';
            document.getElementById('roomOff').value = d?d.lights_off:'20:00';
            document.getElementById('roomTemp').value = d?d.temp_sensor_id:'';
            document.getElementById('roomHum').value = d?d.humidity_sensor_id:'';
            document.getElementById('roomTitle').innerText = d ? 'Edit Room' : 'Add Room';
            showModal('roomModal');
        }
        
        function showZoneModal(rid, rname, d=null) {
            document.getElementById('zRoomId').value=rid;
            document.getElementById('zoneAction').value = d ? 'edit_zone' : 'add_zone';
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
