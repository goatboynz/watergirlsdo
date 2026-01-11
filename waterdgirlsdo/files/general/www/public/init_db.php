<?php

date_default_timezone_set('Pacific/Auckland'); 

function initializeDatabase($dbPath = '/data/waterdgirlsdo.db') {
    try {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            throw new Exception("Directory does not exist: $dir");
        }

        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON;');

        // --- MIGRATIONS ---

        // 1. Rooms Migrations
        $res = $pdo->query("PRAGMA table_info(Rooms)")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_column($res, 'name');
        if (!in_array('lights_on', $cols)) $pdo->exec("ALTER TABLE Rooms ADD COLUMN lights_on TIME DEFAULT '08:00'");
        if (!in_array('lights_off', $cols)) $pdo->exec("ALTER TABLE Rooms ADD COLUMN lights_off TIME DEFAULT '20:00'");
        if (!in_array('temp_sensor_id', $cols)) $pdo->exec("ALTER TABLE Rooms ADD COLUMN temp_sensor_id TEXT");
        if (!in_array('humidity_sensor_id', $cols)) $pdo->exec("ALTER TABLE Rooms ADD COLUMN humidity_sensor_id TEXT");
        for($i=1; $i<=5; $i++) {
            if (!in_array("moisture_sensor_$i", $cols)) $pdo->exec("ALTER TABLE Rooms ADD COLUMN moisture_sensor_$i TEXT");
            if (!in_array("ec_sensor_$i", $cols)) $pdo->exec("ALTER TABLE Rooms ADD COLUMN ec_sensor_$i TEXT");
        }

        // 2. Zones Migrations
        $res = $pdo->query("PRAGMA table_info(Zones)")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_column($res, 'name');
        if (!in_array('plants_count', $cols)) $pdo->exec("ALTER TABLE Zones ADD COLUMN plants_count INTEGER DEFAULT 1");
        if (!in_array('drippers_per_plant', $cols)) $pdo->exec("ALTER TABLE Zones ADD COLUMN drippers_per_plant INTEGER DEFAULT 1");
        if (!in_array('dripper_flow_rate', $cols)) $pdo->exec("ALTER TABLE Zones ADD COLUMN dripper_flow_rate FLOAT DEFAULT 2000");
        if (!in_array('moisture_sensor_id', $cols)) $pdo->exec("ALTER TABLE Zones ADD COLUMN moisture_sensor_id TEXT");
        if (!in_array('ec_sensor_id', $cols)) $pdo->exec("ALTER TABLE Zones ADD COLUMN ec_sensor_id TEXT");

        // 3. IrrigationLogs Migrations
        $res = $pdo->query("PRAGMA table_info(IrrigationLogs)")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_column($res, 'name');
        if (!in_array('volume_ml', $cols)) $pdo->exec("ALTER TABLE IrrigationLogs ADD COLUMN volume_ml FLOAT DEFAULT 0");

        // 4. IrrigationEvents Migrations
        $res = $pdo->query("PRAGMA table_info(IrrigationEvents)")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_column($res, 'name');
        if (!in_array('enabled', $cols)) $pdo->exec("ALTER TABLE IrrigationEvents ADD COLUMN enabled INTEGER DEFAULT 1");

        // --- CREATE TABLES (If none exist) ---
        $createTablesSQL = [
            "CREATE TABLE IF NOT EXISTS Rooms (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                lights_on TIME DEFAULT '08:00',
                lights_off TIME DEFAULT '20:00',
                temp_sensor_id TEXT,
                humidity_sensor_id TEXT,
                moisture_sensor_1 TEXT, moisture_sensor_2 TEXT, moisture_sensor_3 TEXT, moisture_sensor_4 TEXT, moisture_sensor_5 TEXT,
                ec_sensor_1 TEXT, ec_sensor_2 TEXT, ec_sensor_3 TEXT, ec_sensor_4 TEXT, ec_sensor_5 TEXT
            );",

            "CREATE TABLE IF NOT EXISTS Zones (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                room_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                pump_entity_id TEXT NOT NULL,
                solenoid_entity_id TEXT,
                plants_count INTEGER DEFAULT 1,
                drippers_per_plant INTEGER DEFAULT 1,
                dripper_flow_rate FLOAT DEFAULT 2000,
                moisture_sensor_id TEXT,
                ec_sensor_id TEXT,
                FOREIGN KEY (room_id) REFERENCES Rooms(id) ON DELETE CASCADE
            );",

            "CREATE TABLE IF NOT EXISTS IrrigationEvents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zone_id INTEGER NOT NULL,
                event_type TEXT CHECK(event_type IN ('P1', 'P2')) DEFAULT 'P1',
                start_time TIME NOT NULL,
                duration_seconds INTEGER NOT NULL,
                days_of_week TEXT DEFAULT '1,2,3,4,5,6,7',
                enabled INTEGER DEFAULT 1,
                FOREIGN KEY (zone_id) REFERENCES Zones(id) ON DELETE CASCADE
            );",

            "CREATE TABLE IF NOT EXISTS IrrigationLogs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zone_id INTEGER NOT NULL,
                event_type TEXT,
                start_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                duration_seconds INTEGER,
                volume_ml FLOAT DEFAULT 0,
                FOREIGN KEY (zone_id) REFERENCES Zones(id) ON DELETE CASCADE
            );"
        ];

        foreach ($createTablesSQL as $sql) {
            $pdo->exec($sql);
        }

        return $pdo;

    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    } catch (Exception $e) {
        die("Initialization error: " . $e->getMessage());
    }
}
?>
