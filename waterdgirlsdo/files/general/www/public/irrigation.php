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
                $stmt = $pdo->prepare("INSERT INTO Rooms (name, description) VALUES (?, ?)");
                $stmt->execute([$_POST['name'], $_POST['description']]);
                break;
            case 'edit_room':
                $stmt = $pdo->prepare("UPDATE Rooms SET name = ?, description = ? WHERE id = ?");
                $stmt->execute([$_POST['name'], $_POST['description'], $_POST['id']]);
                break;
            case 'delete_room':
                $stmt = $pdo->prepare("DELETE FROM Rooms WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                break;
            case 'add_zone':
                $stmt = $pdo->prepare("INSERT INTO Zones (room_id, name, pump_entity_id, solenoid_entity_id, plants_count, drippers_per_plant, dripper_flow_rate) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$_POST['room_id'], $_POST['name'], $_POST['pump_id'], $_POST['solenoid_id'] ?: null, $_POST['plants_count'], $_POST['drippers_per_plant'], $_POST['flow_rate']]);
                break;
            case 'edit_zone':
                $stmt = $pdo->prepare("UPDATE Zones SET name = ?, pump_entity_id = ?, solenoid_entity_id = ?, plants_count = ?, drippers_per_plant = ?, dripper_flow_rate = ? WHERE id = ?");
                $stmt->execute([$_POST['name'], $_POST['pump_id'], $_POST['solenoid_id'] ?: null, $_POST['plants_count'], $_POST['drippers_per_plant'], $_POST['flow_rate'], $_POST['id']]);
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
                
                // Clear existing if requested
                if (isset($_POST['clear_existing'])) {
                    $pdo->prepare("DELETE FROM IrrigationEvents WHERE zone_id = ?")->execute([$zone_id]);
                }

                $stmt = $pdo->prepare("INSERT INTO IrrigationEvents (zone_id, event_type, start_time, duration_seconds) VALUES (?, ?, ?, ?)");

                // P1 Phase
                $p1_start = $_POST['p1_start'];
                $p1_count = intval($_POST['p1_count']);
                $p1_interval = intval($_POST['p1_interval']);
                $p1_duration = (intval($_POST['p1_mins']) * 60) + intval($_POST['p1_secs']);
                
                $timeObj = new DateTime($p1_start);
                for ($i = 0; $i < $p1_count; $i++) {
                    $stmt->execute([$zone_id, 'P1', $timeObj->format('H:i'), $p1_duration]);
                    $timeObj->modify("+{$p1_interval} minutes");
                }
                
                // P2 Phase
                if (isset($_POST['p2_enabled'])) {
                    $p2_count = intval($_POST['p2_count']);
                    $p2_interval = intval($_POST['p2_interval']);
                    $p2_duration = (intval($_POST['p2_mins']) * 60) + intval($_POST['p2_secs']);
                    
                    for ($i = 0; $i < $p2_count; $i++) {
                        $stmt->execute([$zone_id, 'P2', $timeObj->format('H:i'), $p2_duration]);
                        $timeObj->modify("+{$p2_interval} minutes");
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
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Data Fetching
$rooms = $pdo->query("SELECT * FROM Rooms ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$ha_entities = ha_get_entities();
$switches = array_filter($ha_entities, function($e) {
    return in_array(explode('.', $e['entity_id'])[0], ['switch', 'light', 'outlet']);
});

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Waterd Girls Do - Rooms & Control</title>
    <link rel="stylesheet" href="css/waterd.css">
    <style>
        .icon-btn { background: transparent; border: none; color: var(--text-dim); cursor: pointer; padding: 0.2rem; transition: color 0.3s; }
        .icon-btn:hover { color: var(--emerald); }
        .zone-metrics { background: rgba(0,0,0,0.4); padding: 0.8rem; border-radius: 12px; margin-top: 1rem; font-size: 0.85rem; border: 1px solid rgba(255,255,255,0.05); }
        .metric-row { display: flex; justify-content: space-between; margin-bottom: 0.3rem; }
        .strategy-block { background: rgba(255,255,255,0.05); padding: 1rem; border-radius: 15px; margin-bottom: 1rem; border: 1px solid rgba(255,255,255,0.1); }
        .strategy-block h3 { margin-top: 0; font-size: 1rem; color: var(--emerald); }
        .strategy-block.p2 { border-color: var(--gold); }
        .strategy-block.p2 h3 { color: var(--gold); }
    </style>
</head>
<body>
    <header>
        <div class="logo-text">WATERD GIRLS DO</div>
        <nav>
            <ul>
                <li><a href="dashboard.php">Dashboard</a></li>
                <li><a href="irrigation.php" class="active">Rooms</a></li>
            </ul>
        </nav>
    </header>

    <main class="container">
        <div class="room-header">
            <h1>Rooms & Configuration</h1>
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
                    <small style="color:var(--text-dim)"><?= htmlspecialchars($room['description']) ?></small>
                </div>
                <div style="display:flex; gap: 0.5rem;">
                    <button class="btn btn-secondary" onclick="showZoneModal(<?= $room['id'] ?>, '<?= addslashes($room['name']) ?>')">+ Add Zone</button>
                    <form method="POST" onsubmit="return confirm('Delete this room?')">
                        <input type="hidden" name="action" value="delete_room">
                        <input type="hidden" name="id" value="<?= $room['id'] ?>">
                        <button class="btn btn-danger">Delete Room</button>
                    </form>
                </div>
            </div>

            <div class="zone-grid">
                <?php
                $zStmt = $pdo->prepare("SELECT * FROM Zones WHERE room_id = ?");
                $zStmt->execute([$room['id']]);
                $zones = $zStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($zones as $zone): ?>
                <div class="zone-card">
                    <div style="display:flex; justify-content:space-between; align-items:start;">
                        <strong style="display:flex; align-items:center; gap:0.3rem;">
                            <?= htmlspecialchars($zone['name']) ?>
                            <button class="icon-btn" style="font-size:0.8rem;" onclick='showZoneModal(<?= $room['id'] ?>, "", <?= json_encode($zone) ?>)'>✎</button>
                        </strong>
                        <div style="display:flex; gap:0.5rem;">
                            <form method="POST">
                                <input type="hidden" name="action" value="prime">
                                <input type="hidden" name="zone_id" value="<?= $zone['id'] ?>">
                                <button type="submit" class="btn-secondary" style="background:var(--card-bg); color:var(--gold); border:1px solid var(--gold); padding:0.2rem 0.5rem; border-radius:8px; font-size:0.7rem; cursor:pointer;">PRIME</button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="action" value="delete_zone">
                                <input type="hidden" name="id" value="<?= $zone['id'] ?>">
                                <button class="btn-secondary" style="background:transparent; color:var(--danger); border:none; padding:0; font-size:1.2rem; cursor:pointer;">&times;</button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="zone-metrics">
                        <div class="metric-row"><span>Plants:</span> <strong><?= $zone['plants_count'] ?></strong></div>
                        <div class="metric-row"><span>Drippers @ Plant:</span> <strong><?= $zone['drippers_per_plant'] ?></strong></div>
                        <div class="metric-row"><span>Dripper Flow:</span> <strong><?= $zone['dripper_flow_rate'] ?> mL/h</strong></div>
                        <?php
                            $totalHourly = ($zone['plants_count'] * $zone['drippers_per_plant'] * $zone['dripper_flow_rate']);
                        ?>
                        <div class="metric-row" style="border-top:1px solid rgba(255,255,255,0.1); padding-top:0.3rem;"><span>Volume/Sec:</span> <strong><?= number_format($totalHourly/3600, 2) ?> mL</strong></div>
                    </div>

                    <div class="event-list">
                        <?php
                        $eStmt = $pdo->prepare("SELECT * FROM IrrigationEvents WHERE zone_id = ? ORDER BY start_time ASC");
                        $eStmt->execute([$zone['id']]);
                        $events = $eStmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($events as $event): ?>
                        <div class="event-item">
                            <span>
                                <form method="POST" style="display:inline; margin:0;">
                                    <input type="hidden" name="action" value="toggle_event">
                                    <input type="hidden" name="id" value="<?= $event['id'] ?>">
                                    <button type="submit" class="badge" style="background: <?= $event['enabled'] ? 'var(--emerald)' : 'var(--danger)' ?>; color: black; border:none; cursor:pointer; font-size:0.6rem; padding: 0.1rem 0.3rem; margin-right: 0.5rem;">
                                        <?= $event['enabled'] ? 'ON' : 'OFF' ?>
                                    </button>
                                </form>
                                <span class="badge badge-<?= strtolower($event['event_type']) ?>"><?= $event['event_type'] ?></span>
                                <strong><?= $event['start_time'] ?></strong>
                                <button class="icon-btn" style="font-size:0.7rem;" onclick='showEventModal(<?= $zone['id'] ?>, "", <?= json_encode($event) ?>)'>✎</button>
                            </span>
                            <span style="display:flex; gap:0.5rem; align-items:center;">
                                <small><?= floor($event['duration_seconds']/60) ?>m <?= $event['duration_seconds']%60 ?>s</small>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="delete_event">
                                    <input type="hidden" name="id" value="<?= $event['id'] ?>">
                                    <button class="btn-secondary" style="background:transparent; color:var(--text-dim); border:none; padding:0; cursor:pointer;">&times;</button>
                                </form>
                            </span>
                        </div>
                        <?php endforeach; ?>
                        
                        <div style="display:flex; gap:0.5rem; margin-top:0.5rem;">
                             <button class="btn btn-secondary" style="flex:1; padding:0.4rem; font-size:0.75rem;" onclick="showEventModal(<?= $zone['id'] ?>, '<?= addslashes($zone['name']) ?>')">Manual Entry</button>
                             <button class="btn btn-primary" style="flex:1; padding:0.4rem; font-size:0.75rem;" onclick="showShotEngineModal(<?= $zone['id'] ?>, '<?= addslashes($zone['name']) ?>')">Shot Engine</button>
                        </div>
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
            <h2 id="roomModalTitle">Add New Room</h2>
            <form method="POST">
                <input type="hidden" name="action" id="roomAction" value="add_room">
                <input type="hidden" name="id" id="roomIdInput">
                <input type="text" name="name" id="roomNameInput" placeholder="Room Name" required>
                <textarea name="description" id="roomDescInput" placeholder="Description"></textarea>
                <div class="grid-2">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('roomModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="roomSubmitBtn">Create Room</button>
                </div>
            </form>
        </div>
    </div>

    <div id="zoneModal" class="modal-backdrop">
        <div class="modal">
            <h2 id="zoneModalTitle">Zone Setup</h2>
            <form method="POST">
                <input type="hidden" name="action" id="zoneAction" value="add_zone">
                <input type="hidden" name="id" id="zoneIdInput">
                <input type="hidden" name="room_id" id="modalRoomId">
                <input type="text" name="name" id="zoneNameInput" placeholder="Zone Name" required>
                
                <div class="grid-2">
                    <label><small>Pump</small>
                        <select name="pump_id" id="zonePumpInput" required>
                            <?php foreach ($switches as $sw): ?>
                            <option value="<?= $sw['entity_id'] ?>"><?= $sw['attributes']['friendly_name'] ?? $sw['entity_id'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><small>Solenoid (Opt)</small>
                        <select name="solenoid_id" id="zoneSolenoidInput">
                            <option value="">None</option>
                            <?php foreach ($switches as $sw): ?>
                            <option value="<?= $sw['entity_id'] ?>"><?= $sw['attributes']['friendly_name'] ?? $sw['entity_id'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="grid" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:0.5rem;">
                    <label><small>Plants</small>
                        <input type="number" name="plants_count" id="zonePlantsInput" value="1" min="1">
                    </label>
                    <label><small>Drippers/P</small>
                        <input type="number" name="drippers_per_plant" id="zoneDrippersInput" value="1" min="1">
                    </label>
                    <label><small>mL/h (per d)</small>
                        <input type="number" name="flow_rate" id="zoneFlowInput" value="2000" min="0">
                    </label>
                </div>

                <div class="grid-2">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('zoneModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="zoneSubmitBtn">Save Zone</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Strategy Engine Modal -->
    <div id="shotEngineModal" class="modal-backdrop">
        <div class="modal" style="max-width: 600px;">
            <h2>Daily Irrigation Strategy Builder</h2>
            <form method="POST">
                <input type="hidden" name="action" value="shot_engine">
                <input type="hidden" name="zone_id" id="shotZoneId">
                
                <div class="strategy-block">
                    <h3>⚡ Phase 1: Ramp-Up (Field Capacity)</h3>
                    <div class="grid-2">
                        <label><small>P1 Start Time</small><input type="time" name="p1_start" value="08:00" required></label>
                        <label><small>Shot Count</small><input type="number" name="p1_count" value="5" min="1"></label>
                    </div>
                    <div class="grid-2" style="margin-top:0.5rem;">
                        <label><small>Interval (Mins)</small><input type="number" name="p1_interval" value="30" min="1"></label>
                        <label><small>P1 Duration</small>
                            <div style="display:flex; gap:0.3rem;">
                                <input type="number" name="p1_mins" value="0"><small>m</small>
                                <input type="number" name="p1_secs" value="45"><small>s</small>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="strategy-block p2">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <h3>🌿 Phase 2: Maintenance (Transpiration Control)</h3>
                        <label style="display:flex; align-items:center; gap:0.5rem; margin:0;">
                            <input type="checkbox" name="p2_enabled" id="p2_toggle" checked> <small>Enabled</small>
                        </label>
                    </div>
                    <div id="p2_settings">
                        <div class="grid-2" style="margin-top:0.5rem;">
                            <label><small>Shot Count</small><input type="number" name="p2_count" value="10" min="1"></label>
                            <label><small>Interval (Mins)</small><input type="number" name="p2_interval" value="60" min="1"></label>
                        </div>
                        <div style="margin-top:0.5rem;">
                             <label><small>P2 Duration</small>
                                <div style="display:flex; gap:0.3rem;">
                                    <input type="number" name="p2_mins" value="0"><small>m</small>
                                    <input type="number" name="p2_secs" value="30"><small>s</small>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom:1.5rem;">
                    <label style="display:flex; align-items:center; gap:0.5rem;">
                        <input type="checkbox" name="clear_existing" checked> <small>Clear existing events for this zone</small>
                    </label>
                </div>

                <div class="grid-2">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('shotEngineModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background:var(--primary-gradient);">Build Strategy</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Manual Event Modal -->
    <div id="eventModal" class="modal-backdrop">
        <div class="modal">
            <h2 id="eventModalTitle">Manual Entry</h2>
            <form method="POST">
                <input type="hidden" name="action" id="eventAction" value="add_event">
                <input type="hidden" name="id" id="eventIdInput">
                <input type="hidden" name="zone_id" id="modalZoneId">
                <select name="type" id="eventTypeInput"><option value="P1">P1</option><option value="P2">P2</option></select>
                <div class="grid-2">
                    <input type="time" name="start_time" id="eventTimeInput" required>
                    <div style="display:flex; gap:0.3rem;"><input type="number" name="mins" id="eventMinsInput" value="0"><small>m</small><input type="number" name="secs" id="eventSecsInput" value="0"><small>s</small></div>
                </div>
                <div class="grid-2">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('eventModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="eventSubmitBtn">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showModal(id) { document.getElementById(id).style.display = 'flex'; }
        function hideModal(id) { document.getElementById(id).style.display = 'none'; }
        
        document.getElementById('p2_toggle')?.addEventListener('change', function(e) {
            document.getElementById('p2_settings').style.opacity = e.target.checked ? '1' : '0.3';
            document.getElementById('p2_settings').style.pointerEvents = e.target.checked ? 'all' : 'none';
        });

        function showRoomModal(data = null) {
            if(data) {
                document.getElementById('roomAction').value = 'edit_room';
                document.getElementById('roomIdInput').value = data.id;
                document.getElementById('roomNameInput').value = data.name;
                document.getElementById('roomDescInput').value = data.description;
                document.getElementById('roomModalTitle').innerText = 'Edit Room';
            } else {
                document.getElementById('roomAction').value = 'add_room';
                document.getElementById('roomNameInput').value = '';
                document.getElementById('roomDescInput').value = '';
                document.getElementById('roomModalTitle').innerText = 'Add Room';
            }
            showModal('roomModal');
        }

        function showZoneModal(roomId, name, data = null) {
            document.getElementById('modalRoomId').value = roomId;
            if(data) {
                document.getElementById('zoneAction').value = 'edit_zone';
                document.getElementById('zoneIdInput').value = data.id;
                document.getElementById('zoneNameInput').value = data.name;
                document.getElementById('zonePumpInput').value = data.pump_entity_id;
                document.getElementById('zoneSolenoidInput').value = data.solenoid_entity_id || '';
                document.getElementById('zonePlantsInput').value = data.plants_count;
                document.getElementById('zoneDrippersInput').value = data.drippers_per_plant;
                document.getElementById('zoneFlowInput').value = data.dripper_flow_rate;
                document.getElementById('zoneModalTitle').innerText = 'Edit Zone Settings';
            } else {
                document.getElementById('zoneAction').value = 'add_zone';
                document.getElementById('zoneNameInput').value = '';
                document.getElementById('zoneModalTitle').innerText = 'Add Zone to ' + name;
            }
            showModal('zoneModal');
        }

        function showEventModal(zoneId, name, data = null) {
            document.getElementById('modalZoneId').value = zoneId;
            if(data) {
                document.getElementById('eventAction').value = 'edit_event';
                document.getElementById('eventIdInput').value = data.id;
                document.getElementById('eventTypeInput').value = data.event_type;
                document.getElementById('eventTimeInput').value = data.start_time;
                document.getElementById('eventMinsInput').value = Math.floor(data.duration_seconds / 60);
                document.getElementById('eventSecsInput').value = data.duration_seconds % 60;
                document.getElementById('eventModalTitle').innerText = 'Edit Entry';
            } else {
                document.getElementById('eventAction').value = 'add_event';
                document.getElementById('eventTimeInput').value = '';
                document.getElementById('eventModalTitle').innerText = 'Add Entry to ' + name;
            }
            showModal('eventModal');
        }

        function showShotEngineModal(zoneId, name) {
            document.getElementById('shotZoneId').value = zoneId;
            showModal('shotEngineModal');
        }
    </script>
</body>
</html>
