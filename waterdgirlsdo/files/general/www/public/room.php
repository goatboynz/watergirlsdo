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

function calculateVPD($temp, $humidity) {
    if ($temp === null || $humidity === null || $temp === '' || $humidity === '') return null;
    $temp = floatval($temp);
    $humidity = floatval($humidity);
    $vp_sat = 0.61078 * exp((17.27 * $temp) / ($temp + 237.3));
    $vpd = $vp_sat * (1 - $humidity / 100);
    return round($vpd, 2);
}

$ha_entities = ha_get_entities();
$entities_map = [];
foreach ($ha_entities as $e) { 
    $val = $e['state'] ?? '--';
    if (is_numeric($val)) $val = round(floatval($val), 1);
    $entities_map[$e['entity_id']] = $val; 
}

$switches = array_filter($ha_entities, function($e) { return in_array(explode('.', $e['entity_id'])[0], ['switch', 'light', 'outlet']); });

$temp = $entities_map[$room['temp_sensor_id']] ?? '--';
$hum = $entities_map[$room['humidity_sensor_id']] ?? '--';
$vpd = calculateVPD($temp, $hum) ?? '--';

// Get Zones
$zStmt = $pdo->prepare("SELECT * FROM Zones WHERE room_id = ? ORDER BY name ASC");
$zStmt->execute([$roomId]);
$zones = $zStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($room['name']) ?> - Waterd Girls Do</title>
    <link rel="stylesheet" href="css/waterd.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .room-header-flex { display: flex; justify-content: space-between; align-items: start; margin-bottom: 2rem; }
        .stat-banner { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: var(--card-bg); padding: 1.5rem; border-radius: 20px; border: 1px solid rgba(255,255,255,0.05); text-align: center; }
        .stat-card small { display: block; color: var(--text-dim); text-transform: uppercase; font-size: 0.7rem; letter-spacing: 1px; margin-bottom: 0.5rem; }
        .stat-card strong { font-size: 1.8rem; display: block; }
        .graph-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem; }
        @media (max-width: 1000px) { .graph-grid { grid-template-columns: 1fr; } }
        .graph-box { background: var(--card-bg); border-radius: 24px; padding: 1.5rem; border: 1px solid rgba(255,255,255,0.05); }
        .zone-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem; }
        .badge-env { background: var(--emerald); color: #000; font-weight: 800; padding: 0.2rem 0.6rem; border-radius: 6px; font-size: 0.7rem; }
        .badge-soil { background: var(--gold); color: #000; font-weight: 800; padding: 0.2rem 0.6rem; border-radius: 6px; font-size: 0.7rem; }
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
        <div class="room-header-flex">
            <div>
                <a href="irrigation.php" style="color:var(--text-dim); text-decoration:none; font-size:0.9rem;">← Back to Rooms</a>
                <h1 style="margin-top:0.5rem;"><?= htmlspecialchars($room['name']) ?></h1>
                <p style="color:var(--text-dim);"><?= htmlspecialchars($room['description']) ?></p>
            </div>
            <div style="display:flex; gap:0.5rem;">
                <a href="room_settings.php?id=<?= $room['id'] ?>" class="btn btn-secondary">Room Settings</a>
            </div>
        </div>

        <div class="stat-banner">
            <div class="stat-card"><small>Temperature</small><strong><?= $temp ?>°C</strong></div>
            <div class="stat-card"><small>Humidity</small><strong><?= $hum ?>%</strong></div>
            <div class="stat-card"><small>VPD</small><strong style="color:var(--emerald);"><?= $vpd ?> kPa</strong></div>
            <div class="stat-card"><small>Lights</small><strong><?= $room['lights_on'] ?> - <?= $room['lights_off'] ?></strong></div>
        </div>

        <div class="graph-grid">
            <div class="graph-box">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                    <h3><span class="badge-env">ENV</span> Air Environment</h3>
                    <select id="envRange" onchange="loadEnvGraph()" style="background:rgba(255,255,255,0.05); color:white; border:none; padding:5px; border-radius:5px;">
                        <option value="1h">Last 1h</option>
                        <option value="24h" selected>Last 24h</option>
                        <option value="7d">Last 7d</option>
                    </select>
                </div>
                <div style="height:300px;"><canvas id="envChart"></canvas></div>
            </div>

            <div class="graph-box">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                    <h3><span class="badge-soil">SOIL</span> Substrate Analytics</h3>
                    <select id="soilRange" onchange="loadSoilGraph()" style="background:rgba(255,255,255,0.05); color:white; border:none; padding:5px; border-radius:5px;">
                        <option value="1h">Last 1h</option>
                        <option value="24h" selected>Last 24h</option>
                        <option value="7d">Last 7d</option>
                    </select>
                </div>
                <div style="height:300px;"><canvas id="soilChart"></canvas></div>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:end; margin-bottom:1.5rem;">
            <h2>Irrigation Zones</h2>
            <button class="btn btn-primary" onclick="showZoneModal()">+ Add Zone</button>
        </div>

        <div class="zone-grid">
            <?php foreach($zones as $z): ?>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:start;">
                    <h3 style="margin:0;"><?= htmlspecialchars($z['name']) ?></h3>
                    <button class="icon-btn" onclick='editZone(<?= json_encode($z) ?>)'>✎</button>
                </div>
                <p style="color:var(--text-dim); font-size:0.8rem; margin:0.5rem 0 1rem 0;">
                    <?= $z['plants_count'] ?> Plants • <?= $z['drippers_per_plant'] ?>x <?= $z['dripper_flow_rate'] ?>ml/h
                </p>
                
                <div style="display:flex; flex-direction:column; gap:0.5rem;">
                    <a href="irrigation.php?action=prime&zone_id=<?= $z['id'] ?>" class="btn btn-secondary" style="font-size:0.7rem; text-align:center;">Quick Prime (5s)</a>
                </div>
                
                <hr style="opacity:0.05; margin:1.5rem 0;">
                
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                    <strong style="font-size:0.8rem;">Scheduled Shots</strong>
                    <div style="display:flex; gap:0.4rem;">
                        <button class="btn btn-secondary" style="font-size:0.7rem; padding:0.2rem 0.6rem;" onclick="showEventModal({zone_id: <?= $z['id'] ?>})">+ Add Shot</button>
                        <button class="btn btn-primary" style="font-size:0.7rem; padding:0.2rem 0.6rem;" onclick="showShotEngineModal(<?= $z['id'] ?>)">Engine</button>
                    </div>
                </div>
                
                <div class="event-list" style="max-height:200px; overflow-y:auto; padding-right:5px;">
                    <?php
                    $es = $pdo->prepare("SELECT * FROM IrrigationEvents WHERE zone_id = ? ORDER BY start_time ASC");
                    $es->execute([$z['id']]);
                    foreach ($es->fetchAll(PDO::FETCH_ASSOC) as $ev):
                    ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:0.6rem; background:rgba(0,0,0,0.2); border-radius:10px; margin-bottom:0.4rem; font-size:0.8rem;">
                        <div style="cursor:pointer;" onclick='showEventModal(<?= json_encode($ev) ?>)'>
                            <span class="badge badge-<?= strtolower($ev['event_type']) ?>"><?= $ev['event_type'] ?></span>
                            <strong style="margin-left:5px;"><?= $ev['start_time'] ?></strong>
                            <small style="color:var(--text-dim); margin-left:5px;"><?= floor($ev['duration_seconds']/60) ?>m <?= $ev['duration_seconds']%60 ?>s</small>
                        </div>
                        <form method="POST" action="irrigation.php" style="margin:0;"><input type="hidden" name="action" value="delete_event"><input type="hidden" name="id" value="<?= $ev['id'] ?>"><button type="submit" style="background:none; border:none; color:var(--danger); cursor:pointer; font-size:1.1rem;">×</button></form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>

    <!-- Modals -->
    <div id="zoneModal" class="modal-backdrop">
        <div class="modal">
            <h2 id="zoneTitle">Zone Configuration</h2>
            <form method="POST" action="irrigation.php">
                <input type="hidden" name="action" id="zoneAction" value="add_zone">
                <input type="hidden" name="id" id="zoneId">
                <input type="hidden" name="room_id" value="<?= $roomId ?>">
                
                <label>Zone Name</label><input type="text" name="name" id="zoneName" required>
                
                <div class="grid-2">
                    <label>Pump</label>
                    <select name="pump_id" id="zPump">
                        <?php foreach($switches as $sw){ echo "<option value='{$sw['entity_id']}'>{$sw['attributes']['friendly_name']}</option>"; } ?>
                    </select>
                    <label>Solenoid (Optional)</label>
                    <select name="solenoid_id" id="zSol">
                        <option value="">None</option>
                        <?php foreach($switches as $sw){ echo "<option value='{$sw['entity_id']}'>{$sw['attributes']['friendly_name']}</option>"; } ?>
                    </select>
                </div>
                
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:0.5rem; margin-bottom:1rem;">
                    <label><small>Plants</small><input type="number" name="plants_count" id="zPlants" value="1"></label>
                    <label><small>Drippers/P</small><input type="number" name="drippers_per_plant" id="zDripP" value="1"></label>
                    <label><small>mL/h Flow</small><input type="number" name="flow_rate" id="zFlow" value="2000"></label>
                </div>

                <div class="grid-2">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('zoneModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Zone</button>
                </div>
            </form>
            <form method="POST" action="irrigation.php" id="deleteZoneForm" style="margin-top:1rem; display:none;" onsubmit="return confirm('Delete Zone and ALL its scheduled shots?')">
                <input type="hidden" name="action" value="delete_zone">
                <input type="hidden" name="id" id="deleteZoneId">
                <button type="submit" style="width:100%; cursor:pointer; background:none; border:1px solid var(--danger); color:var(--danger); padding:0.5rem; border-radius:10px; font-size:12px;">Delete Zone Permanently</button>
            </form>
        </div>
    </div>

    <div id="shotEngineModal" class="modal-backdrop">
        <div class="modal" style="max-width:600px;">
            <h2>Strategy Builder</h2>
            <form method="POST" action="irrigation.php">
                <input type="hidden" name="action" value="shot_engine">
                <input type="hidden" name="zone_id" id="seZoneId">
                
                <div style="background:rgba(255,255,255,0.03); padding:1rem; border-radius:15px; margin-bottom:1rem;">
                    <h4 style="margin:0 0 1rem 0; color:var(--emerald);">Phase 1: Ramp Up</h4>
                    <div class="grid-2">
                        <label>Start</label><input type="time" name="p1_start" value="08:00">
                        <label>Shots</label><input type="number" name="p1_count" value="5">
                    </div>
                    <div class="grid-2">
                        <label>Interval (Min)</label><input type="number" name="p1_interval" value="30">
                        <label>Duration</label>
                        <div style="display:flex; gap:2px;">
                            <input type="number" name="p1_mins" value="0" style="width:60px;" placeholder="M">
                            <input type="number" name="p1_secs" value="45" style="width:60px;" placeholder="S">
                        </div>
                    </div>
                </div>

                <div style="background:rgba(255,255,255,0.03); padding:1rem; border-radius:15px; margin-bottom:1rem;">
                    <div style="display:flex; justify-content:space-between;">
                        <h4 style="margin:0; color:var(--gold);">Phase 2: Maintenance</h4>
                        <label style="display:flex; gap:5px; align-items:center; font-size:12px;"><input type="checkbox" name="p2_enabled" checked> Enable</label>
                    </div>
                    <div class="grid-2" style="margin-top:1rem;">
                        <label>Shots</label><input type="number" name="p2_count" value="10">
                        <label>Interval (Min)</label><input type="number" name="p2_interval" value="60">
                    </div>
                    <label>Duration</label>
                    <div style="display:flex; gap:2px;">
                        <input type="number" name="p2_mins" value="0" style="width:60px;" placeholder="M">
                        <input type="number" name="p2_secs" value="30" style="width:60px;" placeholder="S">
                    </div>
                </div>
                
                <label style="display:flex; align-items:center; gap:0.5rem; margin-bottom:1rem; font-size:13px;">
                    <input type="checkbox" name="clear_existing" checked> Clear all existing shots for this zone first
                </label>
                
                <div class="grid-2">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('shotEngineModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Generate Plan</button>
                </div>
            </form>
        </div>
    </div>

    <div id="eventModal" class="modal-backdrop">
        <div class="modal">
            <h2 id="eventTitle">Strategic Shot</h2>
            <form method="POST" action="irrigation.php">
                <input type="hidden" name="action" id="eventAction"><input type="hidden" name="id" id="eventId"><input type="hidden" name="zone_id" id="eventZoneId">
                <div class="grid-2">
                    <label>Type</label>
                    <select name="type" id="eventType">
                        <option value="P1">P1 (Ramp Up)</option>
                        <option value="P2">P2 (Maintenance)</option>
                    </select>
                    <label>Start Time</label><input type="time" name="start_time" id="eventStart" required>
                </div>
                <label>Duration</label>
                <div style="display:flex; gap:0.5rem;">
                    <input type="number" name="mins" id="eventMins" placeholder="Mins">
                    <input type="number" name="secs" id="eventSecs" placeholder="Secs">
                </div>
                <div class="grid-2" style="margin-top:1.5rem;">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('eventModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Shot</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showModal(id) { document.getElementById(id).style.display='flex'; }
        function hideModal(id) { document.getElementById(id).style.display='none'; }

        function showZoneModal() {
            document.getElementById('zoneTitle').innerText = "Add Zone";
            document.getElementById('zoneAction').value = "add_zone";
            document.getElementById('zoneId').value = "";
            document.getElementById('zoneName').value = "";
            document.getElementById('deleteZoneForm').style.display = 'none';
            showModal('zoneModal');
        }

        function editZone(z) {
            document.getElementById('zoneTitle').innerText = "Edit Zone";
            document.getElementById('zoneAction').value = "edit_zone";
            document.getElementById('zoneId').value = z.id;
            document.getElementById('zoneName').value = z.name;
            document.getElementById('zPump').value = z.pump_entity_id;
            document.getElementById('zSol').value = z.solenoid_entity_id || "";
            document.getElementById('zPlants').value = z.plants_count;
            document.getElementById('zDripP').value = z.drippers_per_plant;
            document.getElementById('zFlow').value = z.dripper_flow_rate;
            
            document.getElementById('deleteZoneId').value = z.id;
            document.getElementById('deleteZoneForm').style.display = 'block';
            showModal('zoneModal');
        }

        function showShotEngineModal(zid) {
            document.getElementById('seZoneId').value = zid;
            showModal('shotEngineModal');
        }

        function showEventModal(d) {
            document.getElementById('eventAction').value = d.id ? 'edit_event' : 'add_event';
            document.getElementById('eventId').value = d.id || '';
            document.getElementById('eventZoneId').value = d.zone_id;
            document.getElementById('eventType').value = d.event_type || 'P1';
            document.getElementById('eventStart').value = d.start_time || '';
            document.getElementById('eventMins').value = d.duration_seconds ? Math.floor(d.duration_seconds/60) : 0;
            document.getElementById('eventSecs').value = d.duration_seconds ? d.duration_seconds%60 : 0;
            showModal('eventModal');
        }

        let envChart = null;
        let soilChart = null;

        async function loadEnvGraph() {
            const range = document.getElementById('envRange').value;
            const tempId = '<?= $room['temp_sensor_id'] ?>';
            const humId = '<?= $room['humidity_sensor_id'] ?>';
            
            if(!tempId && !humId) return;

            const res = await fetch(`api_history.php?entity_id=${tempId},${humId}&range=${range}`).then(r => r.json());

            const ctx = document.getElementById('envChart').getContext('2d');
            if(envChart) envChart.destroy();

            envChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: res.labels,
                    datasets: [
                        { label: 'Temp (°C)', data: res.datasets[tempId] || [], borderColor: '#e74c3c', tension: 0.3, pointRadius: 0, borderWidth: 2 },
                        { label: 'Humidity (%)', data: res.datasets[humId] || [], borderColor: '#3498db', tension: 0.3, pointRadius: 0, borderWidth: 2 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: '#fff' } } },
                    scales: {
                        y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#a0a0a0' } },
                        x: { grid: { display: false }, ticks: { color: '#a0a0a0', autoSkip: true, maxTicksLimit: 10 } }
                    }
                }
            });
        }

        async function loadSoilGraph() {
            const range = document.getElementById('soilRange').value;
            const mSensors = [
                '<?= $room['moisture_sensor_1'] ?>', '<?= $room['moisture_sensor_2'] ?>', '<?= $room['moisture_sensor_3'] ?>',
                '<?= $room['moisture_sensor_4'] ?>', '<?= $room['moisture_sensor_5'] ?>'
            ].filter(id => id);
            
            const eSensors = [
                '<?= $room['ec_sensor_1'] ?>', '<?= $room['ec_sensor_2'] ?>', '<?= $room['ec_sensor_3'] ?>',
                '<?= $room['ec_sensor_4'] ?>', '<?= $room['ec_sensor_5'] ?>'
            ].filter(id => id);

            if(mSensors.length === 0 && eSensors.length === 0) return;

            const allIds = [...mSensors, ...eSensors].join(',');
            const res = await fetch(`api_history.php?entity_id=${allIds}&range=${range}`).then(r => r.json());
            
            const datasets = [];
            const colors = ['#2ecc71', '#27ae60', '#16a085', '#1abc9c', '#34495e', '#f1c40f', '#f39c12', '#e67e22', '#d35400', '#e74c3c'];

            mSensors.forEach((id, i) => {
                datasets.push({
                    label: `VWC ${i+1} (%)`,
                    data: res.datasets[id] || [],
                    borderColor: colors[i],
                    tension: 0.3, pointRadius: 0, borderWidth: 2
                });
            });
            
            eSensors.forEach((id, i) => {
                datasets.push({
                    label: `EC ${i+1}`,
                    data: res.datasets[id] || [],
                    borderColor: colors[i+5],
                    tension: 0.3, pointRadius: 0, borderWidth: 2,
                    borderDash: [5, 5]
                });
            });

            const ctx = document.getElementById('soilChart').getContext('2d');
            if(soilChart) soilChart.destroy();

            soilChart = new Chart(ctx, {
                type: 'line',
                data: { labels: res.labels, datasets: datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: '#fff' } } },
                    scales: {
                        y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#a0a0a0' } },
                        x: { grid: { display: false }, ticks: { color: '#a0a0a0', autoSkip: true, maxTicksLimit: 10 } }
                    }
                }
            });
        }

        window.onload = () => {
            loadEnvGraph();
            loadSoilGraph();
        }
    </script>
</body>
</html>
