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
                $stmt = $pdo->prepare("INSERT INTO Zones (room_id, name, pump_entity_id, solenoid_entity_id) VALUES (?, ?, ?, ?)");
                $stmt->execute([$_POST['room_id'], $_POST['name'], $_POST['pump_id'], $_POST['solenoid_id'] ?: null]);
                break;
            case 'edit_zone':
                $stmt = $pdo->prepare("UPDATE Zones SET name = ?, pump_entity_id = ?, solenoid_entity_id = ? WHERE id = ?");
                $stmt->execute([$_POST['name'], $_POST['pump_id'], $_POST['solenoid_id'] ?: null, $_POST['id']]);
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
        .icon-btn {
            background: transparent;
            border: none;
            color: var(--text-dim);
            cursor: pointer;
            padding: 0.2rem;
            transition: color 0.3s;
        }
        .icon-btn:hover { color: var(--emerald); }
        .edit-controls { display: flex; gap: 0.3rem; align-items: center; }
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
                        <form method="POST">
                            <input type="hidden" name="action" value="delete_zone">
                            <input type="hidden" name="id" value="<?= $zone['id'] ?>">
                            <button class="btn-secondary" style="background:transparent; color:var(--danger); border:none; padding:0; font-size:1.2rem; cursor:pointer;">&times;</button>
                        </form>
                    </div>
                    <small style="display:block; margin: 0.5rem 0; color:var(--text-dim)">
                        Pump: <?= $zone['pump_entity_id'] ?><br>
                        Solenoid: <?= $zone['solenoid_entity_id'] ?: 'None' ?>
                    </small>

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
                        <button class="btn btn-secondary" style="width:100%; padding:0.4rem; font-size:0.8rem; margin-top:0.5rem;" onclick="showEventModal(<?= $zone['id'] ?>, '<?= addslashes($zone['name']) ?>')">Schedule Event</button>
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
            <h2 id="zoneModalTitle">Add Zone</h2>
            <form method="POST">
                <input type="hidden" name="action" id="zoneAction" value="add_zone">
                <input type="hidden" name="id" id="zoneIdInput">
                <input type="hidden" name="room_id" id="modalRoomId">
                <input type="text" name="name" id="zoneNameInput" placeholder="Zone Name" required>
                
                <label><small>Pump Entity</small></label>
                <select name="pump_id" id="zonePumpInput" required>
                    <?php foreach ($switches as $sw): ?>
                    <option value="<?= $sw['entity_id'] ?>"><?= $sw['attributes']['friendly_name'] ?? $sw['entity_id'] ?></option>
                    <?php endforeach; ?>
                </select>

                <label><small>Solenoid Entity (Optional)</small></label>
                <select name="solenoid_id" id="zoneSolenoidInput">
                    <option value="">None / Integrated</option>
                    <?php foreach ($switches as $sw): ?>
                    <option value="<?= $sw['entity_id'] ?>"><?= $sw['attributes']['friendly_name'] ?? $sw['entity_id'] ?></option>
                    <?php endforeach; ?>
                </select>

                <div class="grid-2">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('zoneModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="zoneSubmitBtn">Save Zone</button>
                </div>
            </form>
        </div>
    </div>

    <div id="eventModal" class="modal-backdrop">
        <div class="modal">
            <h2 id="eventModalTitle">Schedule Event</h2>
            <form method="POST">
                <input type="hidden" name="action" id="eventAction" value="add_event">
                <input type="hidden" name="id" id="eventIdInput">
                <input type="hidden" name="zone_id" id="modalZoneId">
                
                <label><small>Event Type</small></label>
                <select name="type" id="eventTypeInput">
                    <option value="P1">P1 (Generative)</option>
                    <option value="P2">P2 (Vegetative)</option>
                </select>

                <div class="grid-2">
                    <div>
                        <label><small>Start Time</small></label>
                        <input type="time" name="start_time" id="eventTimeInput" required>
                    </div>
                    <div>
                        <label><small>Duration</small></label>
                        <div style="display:flex; gap:0.5rem; align-items:center;">
                            <input type="number" name="mins" id="eventMinsInput" value="0" min="0" required><small>m</small>
                            <input type="number" name="secs" id="eventSecsInput" value="0" min="0" max="59" required><small>s</small>
                        </div>
                    </div>
                </div>

                <div class="grid-2">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('eventModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="eventSubmitBtn">Save Event</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showModal(id) { document.getElementById(id).style.display = 'flex'; }
        function hideModal(id) { document.getElementById(id).style.display = 'none'; }
        
        function showRoomModal(data = null) {
            if(data) {
                document.getElementById('roomAction').value = 'edit_room';
                document.getElementById('roomIdInput').value = data.id;
                document.getElementById('roomNameInput').value = data.name;
                document.getElementById('roomDescInput').value = data.description;
                document.getElementById('roomModalTitle').innerText = 'Edit Room';
                document.getElementById('roomSubmitBtn').innerText = 'Update Room';
            } else {
                document.getElementById('roomAction').value = 'add_room';
                document.getElementById('roomNameInput').value = '';
                document.getElementById('roomDescInput').value = '';
                document.getElementById('roomModalTitle').innerText = 'Add New Room';
                document.getElementById('roomSubmitBtn').innerText = 'Create Room';
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
                document.getElementById('zoneModalTitle').innerText = 'Edit Zone';
                document.getElementById('zoneSubmitBtn').innerText = 'Update Zone';
            } else {
                document.getElementById('zoneAction').value = 'add_zone';
                document.getElementById('zoneNameInput').value = '';
                document.getElementById('zoneModalTitle').innerText = 'Add Zone to ' + name;
                document.getElementById('zoneSubmitBtn').innerText = 'Add Zone';
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
                document.getElementById('eventModalTitle').innerText = 'Edit Event';
                document.getElementById('eventSubmitBtn').innerText = 'Update Event';
            } else {
                document.getElementById('eventAction').value = 'add_event';
                document.getElementById('eventTimeInput').value = '';
                document.getElementById('eventMinsInput').value = '0';
                document.getElementById('eventSecsInput').value = '0';
                document.getElementById('eventModalTitle').innerText = 'Schedule: ' + name;
                document.getElementById('eventSubmitBtn').innerText = 'Save Event';
            }
            showModal('eventModal');
        }
    </script>
</body>
</html>
