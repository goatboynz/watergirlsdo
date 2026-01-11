<?php
require_once 'auth.php';
require_once 'init_db.php';
require_once 'ha_api.php';

$pdo = initializeDatabase();

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_room':
                $stmt = $pdo->prepare("INSERT INTO Rooms (name, description) VALUES (?, ?)");
                $stmt->execute([$_POST['name'], $_POST['description']]);
                break;
            case 'add_zone':
                $stmt = $pdo->prepare("INSERT INTO Zones (room_id, name, switch_entity_id) VALUES (?, ?, ?)");
                $stmt->execute([$_POST['room_id'], $_POST['name'], $_POST['switch_entity_id']]);
                break;
            case 'add_event':
                $stmt = $pdo->prepare("INSERT INTO IrrigationEvents (zone_id, start_time, duration_seconds, days_of_week) VALUES (?, ?, ?, ?)");
                $days = isset($_POST['days']) ? implode(',', $_POST['days']) : '1,2,3,4,5,6,7';
                $stmt->execute([$_POST['zone_id'], $_POST['start_time'], $_POST['duration_seconds'], $days]);
                break;
            case 'delete_room':
                $stmt = $pdo->prepare("DELETE FROM Rooms WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                break;
            case 'delete_zone':
                $stmt = $pdo->prepare("DELETE FROM Zones WHERE id = ?");
                $stmt->execute([$_POST['id']]);
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
        header("Location: irrigation.php");
        exit;
    }
}

// Fetch Data
$rooms = $pdo->query("SELECT * FROM Rooms")->fetchAll(PDO::FETCH_ASSOC);
$zones = $pdo->query("SELECT z.*, r.name as room_name FROM Zones z JOIN Rooms r ON z.room_id = r.id")->fetchAll(PDO::FETCH_ASSOC);
$events = $pdo->query("SELECT e.*, z.name as zone_name, r.name as room_name 
                      FROM IrrigationEvents e 
                      JOIN Zones z ON e.zone_id = z.id 
                      JOIN Rooms r ON z.room_id = r.id 
                      ORDER BY e.start_time ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch HA Switch Entities
$all_entities = ha_get_entities();
$switches = array_filter($all_entities, function($e) {
    $domain = explode('.', $e['entity_id'])[0];
    return in_array($domain, ['switch', 'light', 'outlet']);
});

?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Waterd Girls Do - Irrigation Control</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="css/growcart.css">
    <style>
        .event-card { margin-bottom: 1rem; padding: 1rem; border-radius: 8px; border: 1px solid var(--pico-muted-border-color); }
        .days-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
        .days-grid label { font-size: 0.8rem; text-align: center; }
        .zone-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--pico-muted-border-color); margin-bottom: 1rem; padding-bottom: 0.5rem; }
    </style>
</head>
<body>
    <header class="container-fluid">
        <?php require_once 'nav.php'; ?>
    </header>

    <main class="container">
        <h1>Irrigation Management</h1>

        <div class="grid">
            <!-- Rooms Section -->
            <article>
                <header><strong>Rooms</strong></header>
                <table role="grid">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rooms as $room): ?>
                        <tr>
                            <td><?= htmlspecialchars($room['name']) ?></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_room">
                                    <input type="hidden" name="id" value="<?= $room['id'] ?>">
                                    <button type="submit" class="outline contrast" onclick="return confirm('Delete room and all its zones?')">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <details>
                    <summary role="button" class="secondary">Add Room</summary>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_room">
                        <input type="text" name="name" placeholder="Room Name" required>
                        <textarea name="description" placeholder="Description"></textarea>
                        <button type="submit">Save Room</button>
                    </form>
                </details>
            </article>

            <!-- Zones Section -->
            <article>
                <header><strong>Zones</strong></header>
                <table role="grid">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Room</th>
                            <th>Entity</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($zones as $zone): ?>
                        <tr>
                            <td><?= htmlspecialchars($zone['name']) ?></td>
                            <td><?= htmlspecialchars($zone['room_name']) ?></td>
                            <td><small><?= htmlspecialchars($zone['switch_entity_id']) ?></small></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_zone">
                                    <input type="hidden" name="id" value="<?= $zone['id'] ?>">
                                    <button type="submit" class="outline contrast" onclick="return confirm('Delete zone?')">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <details>
                    <summary role="button" class="secondary">Add Zone</summary>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_zone">
                        <select name="room_id" required>
                            <option value="">Select Room...</option>
                            <?php foreach ($rooms as $room): ?>
                            <option value="<?= $room['id'] ?>"><?= htmlspecialchars($room['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="name" placeholder="Zone Name" required>
                        <select name="switch_entity_id" required>
                            <option value="">Select HA Switch Entity...</option>
                            <?php foreach ($switches as $sw): ?>
                            <option value="<?= $sw['entity_id'] ?>"><?= htmlspecialchars($sw['attributes']['friendly_name'] ?? $sw['entity_id']) ?> (<?= $sw['entity_id'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit">Save Zone</button>
                    </form>
                </details>
            </article>
        </div>

        <hr>

        <!-- Events Section -->
        <section>
            <div class="zone-header">
                <h2>Irrigation Events (Timers)</h2>
                <details>
                    <summary role="button" class="primary">Add New Event</summary>
                    <form method="POST" style="margin-top: 1rem;">
                        <input type="hidden" name="action" value="add_event">
                        <div class="grid">
                            <label>Zone
                                <select name="zone_id" required>
                                    <option value="">Select Zone...</option>
                                    <?php foreach ($zones as $zone): ?>
                                    <option value="<?= $zone['id'] ?>"><?= htmlspecialchars($zone['room_name']) ?> - <?= htmlspecialchars($zone['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Start Time
                                <input type="time" name="start_time" required>
                            </label>
                            <label>Duration (Seconds)
                                <input type="number" name="duration_seconds" value="60" required>
                            </label>
                        </div>
                        <fieldset>
                            <legend>Days of Week</legend>
                            <div class="days-grid">
                                <?php $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']; ?>
                                <?php foreach ($days as $i => $day): ?>
                                <label>
                                    <input type="checkbox" name="days[]" value="<?= $i+1 ?>" checked>
                                    <?= $day ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                        <button type="submit">Save Event</button>
                    </form>
                </details>
            </div>

            <div class="grid">
                <?php foreach ($events as $event): ?>
                <article class="event-card">
                    <header style="display:flex; justify-content:space-between; align-items:center;">
                        <strong><?= htmlspecialchars($event['room_name']) ?>: <?= htmlspecialchars($event['zone_name']) ?></strong>
                        <form method="POST">
                            <input type="hidden" name="action" value="toggle_event">
                            <input type="hidden" name="id" value="<?= $event['id'] ?>">
                            <button type="submit" class="<?= $event['enabled'] ? '' : 'outline' ?> secondary" style="padding: 0.2rem 0.5rem; font-size: 0.8rem;">
                                <?= $event['enabled'] ? 'Enabled' : 'Disabled' ?>
                            </button>
                        </form>
                    </header>
                    <p>
                        🕒 Starts: <strong><?= $event['start_time'] ?></strong><br>
                        ⏱ Duration: <strong><?= $event['duration_seconds'] ?>s</strong><br>
                        📅 Days: <small><?= $event['days_of_week'] ?></small>
                    </p>
                    <footer>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="action" value="delete_event">
                            <input type="hidden" name="id" value="<?= $event['id'] ?>">
                            <button type="submit" class="outline contrast" style="padding: 0.2rem 0.5rem; font-size: 0.8rem;">Delete Event</button>
                        </form>
                    </footer>
                </article>
                <?php endforeach; ?>
            </div>
        </section>

    </main>

    <script src="js/growcart.js"></script>
</body>
</html>
