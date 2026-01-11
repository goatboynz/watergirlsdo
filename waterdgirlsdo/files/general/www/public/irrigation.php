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
            case 'delete_room': $stmt = $pdo->prepare("DELETE FROM Rooms WHERE id = ?"); $stmt->execute([$_POST['id']]); break;
            case 'add_zone':
                $stmt = $pdo->prepare("INSERT INTO Zones (room_id, name, pump_entity_id, solenoid_entity_id, plants_count, drippers_per_plant, dripper_flow_rate, moisture_sensor_id, ec_sensor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$_POST['room_id'], $_POST['name'], $_POST['pump_id'], $_POST['solenoid_id'] ?: null, $_POST['plants_count'], $_POST['drippers_per_plant'], $_POST['flow_rate'], $_POST['moisture_id'] ?: null, $_POST['ec_id'] ?: null]);
                break;
            case 'edit_zone':
                $stmt = $pdo->prepare("UPDATE Zones SET name = ?, pump_entity_id = ?, solenoid_entity_id = ?, plants_count = ?, drippers_per_plant = ?, dripper_flow_rate = ?, moisture_sensor_id = ?, ec_sensor_id = ? WHERE id = ?");
                $stmt->execute([$_POST['name'], $_POST['pump_id'], $_POST['solenoid_id'] ?: null, $_POST['plants_count'], $_POST['drippers_per_plant'], $_POST['flow_rate'], $_POST['moisture_id'] ?: null, $_POST['ec_id'] ?: null, $_POST['id']]);
                break;
            case 'delete_zone': $stmt = $pdo->prepare("DELETE FROM Zones WHERE id = ?"); $stmt->execute([$_POST['id']]); break;
            case 'add_event':
                $stmt = $pdo->prepare("INSERT INTO IrrigationEvents (zone_id, event_type, start_time, duration_seconds) VALUES (?, ?, ?, ?)");
                $duration = (intval($_POST['mins'] ?? 0) * 60) + intval($_POST['secs'] ?? 0);
                $stmt->execute([$_POST['zone_id'], $_POST['type'], $_POST['start_time'], $duration]);
                break;
            case 'edit_event':
                $stmt = $pdo->prepare("UPDATE IrrigationEvents SET event_type = ?, start_time = ?, duration_seconds = ? WHERE id = ?");
                $duration = (intval($_POST['mins'] ?? 0) * 60) + intval($_POST['secs'] ?? 0);
                $stmt->execute([$_POST['type'], $_POST['start_time'], $duration, $_POST['id']]);
                break;
            case 'delete_event': $stmt = $pdo->prepare("DELETE FROM IrrigationEvents WHERE id = ?"); $stmt->execute([$_POST['id']]); break;
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
            case 'toggle_event': $stmt = $pdo->prepare("UPDATE IrrigationEvents SET enabled = 1 - enabled WHERE id = ?"); $stmt->execute([$_POST['id']]); break;
            case 'prime':
                $zone_id = $_POST['zone_id'];
                $stmt = $pdo->prepare("SELECT pump_entity_id, solenoid_entity_id FROM Zones WHERE id = ?");
                $stmt->execute([$zone_id]);
                $z = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($z) {
                    if ($z['solenoid_entity_id']) ha_set_state($z['solenoid_entity_id'], 'on');
                    ha_set_state($z['pump_entity_id'], 'on');
                    usleep(5000000); ha_set_state($z['pump_entity_id'], 'off');
                    if ($z['solenoid_entity_id']) ha_set_state($z['solenoid_entity_id'], 'off');
                }
                break;
        }
    } catch (Exception $e) { $error = $e->getMessage(); }
    if(!isset($error)) { header("Location: irrigation.php"); exit; }
}

$rooms = $pdo->query("SELECT * FROM Rooms ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$ha_entities = ha_get_entities();
$entities_map = [];
foreach ($ha_entities as $e) { $entities_map[$e['entity_id']] = $e['state'] ?? '--'; }
$switches = array_filter($ha_entities, function($e) { return in_array(explode('.', $e['entity_id'])[0], ['switch', 'light', 'outlet']); });
$sensors = array_filter($ha_entities, function($e) { return explode('.', $e['entity_id'])[0] === 'sensor'; });

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Irrigation - Waterd Girls Do</title>
    <link rel="stylesheet" href="css/waterd.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .room-summary { display: grid; grid-template-columns: 1fr 2fr 1fr; align-items: center; background: rgba(255,255,255,0.03); padding: 1.5rem 2rem; border-radius: 20px; border: 1px solid rgba(255,255,255,0.05); cursor: pointer; transition: all 0.3s; margin-bottom: 1rem; }
        .room-summary:hover { background: rgba(255,255,255,0.06); border-color: var(--emerald); }
        .room-details { display: none; padding: 2rem; background: rgba(0,0,0,0.2); border-radius: 0 0 20px 20px; border: 1px solid rgba(255,255,255,0.05); border-top: none; margin-top: -1.5rem; margin-bottom: 2rem; }
        .room-details.active { display: block; }
        .chevron { transition: transform 0.3s; }
        .active .chevron { transform: rotate(180deg); }
        .room-stat-pill { background: rgba(46, 204, 113, 0.1); border: 1px solid rgba(46, 204, 113, 0.2); color: var(--emerald); padding: 0.4rem 0.8rem; border-radius: 50px; font-weight: 800; font-size: 0.8rem; }
        .zone-card-mini { background: rgba(255,255,255,0.02); padding: 1.5rem; border-radius: 20px; border: 1px solid rgba(255,255,255,0.05); margin-bottom: 1rem; }
        .event-item:hover { background: rgba(255,255,255,0.05); }
        .modal label { font-size: 0.8rem; color: var(--text-dim); margin-bottom: 0.3rem; display: block; }
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
                <h1>Facility Control</h1>
                <p style="color:var(--text-dim);">Expand a room to manage zones and irrigation strategies.</p>
            </div>
            <button class="btn btn-primary" onclick="showRoomModal()">+ New Room</button>
        </div>

        <?php foreach ($rooms as $room): 
            $temp = $entities_map[$room['temp_sensor_id']] ?? null;
            $hum = $entities_map[$room['humidity_sensor_id']] ?? null;
            $vpd = calculateVPD($temp, $hum);
        ?>
        <div class="room-group" id="room-group-<?= $room['id'] ?>">
            <div class="room-summary" onclick="toggleRoom(<?= $room['id'] ?>)">
                <div>
                    <h3 style="margin:0;"><?= htmlspecialchars($room['name']) ?></h3>
                    <small style="color:var(--text-dim);"><?= htmlspecialchars($room['description']) ?></small>
                </div>
                <div style="display:flex; gap:1.5rem; justify-content:center;">
                    <div class="room-stat-pill">🌡️ <?= $temp ?? '--' ?>°C</div>
                    <div class="room-stat-pill">💧 <?= $hum ?? '--' ?>%</div>
                    <div class="room-stat-pill">🌫️ <?= $vpd ?? '--' ?> VPD</div>
                </div>
                <div style="text-align:right;">
                    <span class="chevron">▼</span>
                </div>
            </div>

            <div class="room-details" id="details-<?= $room['id'] ?>">
                <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:2rem; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:1rem;">
                    <div>
                        <span class="badge badge-p1" style="background:rgba(255,255,255,0.05); color:var(--text-dim);">LIGHT CYCLE: <?= $room['lights_on'] ?> - <?= $room['lights_off'] ?></span>
                        <div style="margin-top:1rem; width:300px; height:100px;">
                            <canvas id="roomChart-<?= $room['id'] ?>"></canvas>
                        </div>
                    </div>
                    <div style="display:flex; gap:0.5rem;">
                        <button class="btn btn-secondary" onclick='showRoomModal(<?= json_encode($room) ?>)'>Edit Room</button>
                        <button class="btn btn-primary" onclick="showZoneModal(<?= $room['id'] ?>, '<?= addslashes($room['name']) ?>')">+ Add Zone</button>
                    </div>
                </div>

                <div class="zone-grid">
                    <?php
                    $zs = $pdo->prepare("SELECT * FROM Zones WHERE room_id = ?"); $zs->execute([$room['id']]);
                    foreach ($zs->fetchAll(PDO::FETCH_ASSOC) as $zone): 
                        $vwc = $entities_map[$zone['moisture_sensor_id']] ?? null;
                        $ec = $entities_map[$zone['ec_sensor_id']] ?? null;
                    ?>
                    <div class="zone-card-mini">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                            <h4 style="margin:0;"><?= htmlspecialchars($zone['name']) ?></h4>
                            <div style="display:flex; gap:0.4rem;">
                                <button class="icon-btn" onclick='showZoneModal(<?= $room['id'] ?>, "", <?= json_encode($zone) ?>)'>✎</button>
                                <form method="POST"><input type="hidden" name="action" value="prime"><input type="hidden" name="zone_id" value="<?= $zone['id'] ?>"><button class="btn btn-secondary" style="padding:0.2rem 0.5rem; font-size:0.6rem; color:var(--gold);">PRIME</button></form>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; margin-bottom:1.5rem;">
                            <div style="background:rgba(0,0,0,0.3); padding:0.8rem; border-radius:12px; text-align:center;">
                                <small style="color:var(--text-dim); display:block; font-size:0.6rem;">VWC %</small>
                                <strong style="font-size:1.1rem; color:var(--emerald);"><?= $vwc ?? '--' ?>%</strong>
                            </div>
                            <div style="background:rgba(0,0,0,0.3); padding:0.8rem; border-radius:12px; text-align:center;">
                                <small style="color:var(--text-dim); display:block; font-size:0.6rem;">EC</small>
                                <strong style="font-size:1.1rem; color:var(--gold);"><?= $ec ?? '--' ?></strong>
                            </div>
                        </div>

                        <div class="event-list">
                            <?php
                            $es = $pdo->prepare("SELECT * FROM IrrigationEvents WHERE zone_id = ? ORDER BY start_time ASC"); $es->execute([$zone['id']]);
                            foreach ($es->fetchAll(PDO::FETCH_ASSOC) as $event): ?>
                            <div class="event-item" style="display:flex; justify-content:space-between; align-items:center;">
                                <div style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; flex: 1;" onclick='showEventModal(<?= json_encode($event) ?>)'>
                                    <span class="badge badge-<?= strtolower($event['event_type']) ?>"><?= $event['event_type'] ?></span>
                                    <strong><?= $event['start_time'] ?></strong>
                                    <span style="font-size:0.8rem; color:var(--text-dim);"><?= floor($event['duration_seconds']/60) ?>m <?= $event['duration_seconds']%60 ?>s</span>
                                </div>
                                <form method="POST" onsubmit="return confirm('Delete event?')" style="margin:0;">
                                    <input type="hidden" name="action" value="delete_event">
                                    <input type="hidden" name="id" value="<?= $event['id'] ?>">
                                    <button type="submit" style="background:none; border:none; color:var(--danger); cursor:pointer; font-weight:800; padding:0 0.5rem;">×</button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.5rem; margin-top:1rem;">
                            <button class="btn btn-secondary" style="font-size:0.7rem;" onclick="showEventModal({zone_id: <?= $zone['id'] ?>})">+ Event</button>
                            <button class="btn btn-primary" style="font-size:0.7rem;" onclick="showShotEngineModal(<?= $zone['id'] ?>)">Strategy Builder</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </main>

    <!-- Modals -->
    <div id="roomModal" class="modal-backdrop">
        <div class="modal">
            <h2 id="roomTitle">Add Room</h2>
            <form method="POST">
                <input type="hidden" name="action" id="roomAction" value="add_room"><input type="hidden" name="id" id="roomId">
                <label>Room Name</label><input type="text" name="name" id="roomName" required>
                <div class="grid-2">
                    <label>Lights On</label><input type="time" name="lights_on" id="roomOn">
                    <label>Lights Off</label><input type="time" name="lights_off" id="roomOff">
                </div>
                <div class="grid-2">
                    <label>Temp Sensor</label><select name="temp_id" id="roomTemp"><option value="">None</option><?php foreach($sensors as $s){ echo "<option value='{$s['entity_id']}'>{$s['attributes']['friendly_name']}</option>"; } ?></select>
                    <label>Humidity Sensor</label><select name="hum_id" id="roomHum"><option value="">None</option><?php foreach($sensors as $s){ echo "<option value='{$s['entity_id']}'>{$s['attributes']['friendly_name']}</option>"; } ?></select>
                </div>
                <div class="grid-2"><button type="button" class="btn btn-secondary" onclick="hideModal('roomModal')">Cancel</button><button type="submit" class="btn btn-primary">Save Room</button></div>
            </form>
        </div>
    </div>

    <div id="eventModal" class="modal-backdrop">
        <div class="modal">
            <h2 id="eventTitle">Add Event</h2>
            <form method="POST">
                <input type="hidden" name="action" id="eventAction" value="add_event"><input type="hidden" name="id" id="eventId"><input type="hidden" name="zone_id" id="eventZoneId">
                <div class="grid-2">
                    <label>Type</label><select name="type" id="eventType"><option value="P1">P1 (Ramp Up)</option><option value="P2">P2 (Maintenance)</option></select>
                    <label>Start Time</label><input type="time" name="start_time" id="eventStart" required>
                </div>
                <label>Duration</label>
                <div style="display:flex; gap:0.5rem;">
                    <input type="number" name="mins" id="eventMins" placeholder="Mins" value="0">
                    <input type="number" name="secs" id="eventSecs" placeholder="Secs" value="0">
                </div>
                <div id="deleteEventBtn" style="margin-bottom:1rem; display:none;">
                    <button type="button" class="btn btn-secondary" style="color:var(--danger); width:100%;" onclick="deleteCurrentEvent()">Delete Event</button>
                </div>
                <div class="grid-2"><button type="button" class="btn btn-secondary" onclick="hideModal('eventModal')">Cancel</button><button type="submit" class="btn btn-primary">Save Event</button></div>
            </form>
        </div>
    </div>

    <!-- Strategy Builder & Zone Modals kept same as previous versions but optimized -->
    <div id="zoneModal" class="modal-backdrop">
        <div class="modal"><h2>Zone Configuration</h2><form method="POST"><input type="hidden" name="action" id="zoneAction"><input type="hidden" name="id" id="zoneId"><input type="hidden" name="room_id" id="zRoomId"><label>Zone Name</label><input type="text" name="name" id="zoneName" required><div class="grid-2"><label>Pump</label><select name="pump_id" id="zPump"><?php foreach($switches as $sw){ echo "<option value='{$sw['entity_id']}'>{$sw['attributes']['friendly_name']}</option>"; } ?></select><label>Solenoid</label><select name="solenoid_id" id="zSol"><option value="">None</option><?php foreach($switches as $sw){ echo "<option value='{$sw['entity_id']}'>{$sw['attributes']['friendly_name']}</option>"; } ?></select></div><div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:0.5rem; margin-bottom:1rem;"><label><small>Plants</small><input type="number" name="plants_count" id="zPlants"></label><label><small>Drippers/P</small><input type="number" name="drippers_per_plant" id="zDripP"></label><label><small>mL/h Flow</small><input type="number" name="flow_rate" id="zFlow"></label></div><div class="grid-2"><label>Moisture Sensor</label><select name="moisture_id" id="zMoist"><option value="">None</option><?php foreach($sensors as $s){ echo "<option value='{$s['entity_id']}'>{$s['attributes']['friendly_name']}</option>"; } ?></select><label>EC Sensor</label><select name="ec_id" id="zEc"><option value="">None</option><?php foreach($sensors as $s){ echo "<option value='{$s['entity_id']}'>{$s['attributes']['friendly_name']}</option>"; } ?></select></div><div class="grid-2"><button type="button" class="btn btn-secondary" onclick="hideModal('zoneModal')">Cancel</button><button type="submit" class="btn btn-primary">Save Zone</button></div></form></div>
    </div>
    <div id="shotEngineModal" class="modal-backdrop">
        <div class="modal" style="max-width:600px;"><h2>Strategy Builder</h2><form method="POST"><input type="hidden" name="action" value="shot_engine"><input type="hidden" name="zone_id" id="seZoneId"><div style="background:rgba(255,255,255,0.03); padding:1rem; border-radius:15px; margin-bottom:1rem;"><h4 style="margin:0 0 1rem 0; color:var(--emerald);">Phase 1: Ramp Up</h4><div class="grid-2"><label>Start Time</label><input type="time" name="p1_start" value="08:00"><label>Shots</label><input type="number" name="p1_count" value="5"></div><div class="grid-2"><label>Interval (Min)</label><input type="number" name="p1_interval" value="30"><label>Drip Duration</label><div style="display:flex;"><input type="number" name="p1_mins" value="0"><input type="number" name="p1_secs" value="45"></div></div></div><div style="background:rgba(255,255,255,0.03); padding:1rem; border-radius:15px; margin-bottom:1rem;"><div style="display:flex; justify-content:space-between;"><h4 style="margin:0; color:var(--gold);">Phase 2: Maintenance</h4><input type="checkbox" name="p2_enabled" checked></div><div class="grid-2" style="margin-top:1rem;"><label>Shots</label><input type="number" name="p2_count" value="10"><label>Interval (Min)</label><input type="number" name="p2_interval" value="60"></div><label>Drip Duration</label><div style="display:flex;"><input type="number" name="p2_mins" value="0"><input type="number" name="p2_secs" value="30"></div></div><label style="display:flex; align-items:center; gap:0.5rem;"><input type="checkbox" name="clear_existing" checked> Clear existing events</label><div class="grid-2" style="margin-top:1rem;"><button type="button" class="btn btn-secondary" onclick="hideModal('shotEngineModal')">Cancel</button><button type="submit" class="btn btn-primary">Build Plan</button></div></form></div>
    </div>

    <script>
        function showModal(id) { document.getElementById(id).style.display='flex'; }
        function hideModal(id) { document.getElementById(id).style.display='none'; }
        
        function toggleRoom(rid) {
            const details = document.getElementById('details-' + rid);
            const group = document.getElementById('room-group-' + rid);
            details.classList.toggle('active');
            group.classList.toggle('active');
        }

        function showEventModal(d) {
            document.getElementById('eventZoneId').value = d.zone_id;
            document.getElementById('eventAction').value = d.id ? 'edit_event' : 'add_event';
            document.getElementById('eventId').value = d.id || '';
            document.getElementById('eventType').value = d.event_type || 'P1';
            document.getElementById('eventStart').value = d.start_time || '';
            document.getElementById('eventMins').value = d.duration_seconds ? Math.floor(d.duration_seconds/60) : 0;
            document.getElementById('eventSecs').value = d.duration_seconds ? d.duration_seconds%60 : 0;
            document.getElementById('eventTitle').innerText = d.id ? 'Edit Event' : 'Add Strategic Event';
            document.getElementById('deleteEventBtn').style.display = d.id ? 'block' : 'none';
            showModal('eventModal');
        }

        function deleteCurrentEvent() {
            if(confirm('Delete this event?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete_event"><input type="hidden" name="id" value="${document.getElementById('eventId').value}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function showRoomModal(d=null) {
            document.getElementById('roomAction').value = d ? 'edit_room' : 'add_room';
            document.getElementById('roomId').value = d?d.id:'';
            document.getElementById('roomName').value = d?d.name:'';
            document.getElementById('roomOn').value = d?d.lights_on:'';
            document.getElementById('roomOff').value = d?d.lights_off:'';
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

        // Mini Charts for Rooms
        document.addEventListener('DOMContentLoaded', () => {
            <?php foreach($rooms as $r): ?>
            new Chart(document.getElementById('roomChart-<?= $r['id'] ?>'), {
                type: 'line',
                data: { labels: ['6h','5h','4h','3h','2h','1h','Now'], datasets: [{ label: 'VPD', data: [1.1, 1.2, 1.1, 0.9, 1.2, 1.3, <?= calculateVPD($entities_map[$r['temp_sensor_id']] ?? null, $entities_map[$r['humidity_sensor_id']] ?? null) ?: 0 ?>], borderColor: '#2ecc71', tension: 0.4, pointRadius: 0 }] },
                options: { plugins: { legend: { display: false } }, scales: { x: { display: false }, y: { display: false } } }
            });
            <?php endforeach; ?>
        });
    </script>
</body>
</html>
