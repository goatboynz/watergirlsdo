<?php

date_default_timezone_set('Pacific/Auckland'); 


function initializeDatabase($dbPath = '/www/public/waterdgirlsdo.db') {
    try {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            throw new Exception("Directory does not exist: $dir");
        }

        if (!is_writable($dir)) {
            throw new Exception("Directory is not writable: $dir");
        }

        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON;');

        $createTablesSQL = [
            // Irrigation: Rooms
            "CREATE TABLE IF NOT EXISTS Rooms (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT
            );",

            // Irrigation: Zones
            "CREATE TABLE IF NOT EXISTS Zones (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                room_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                switch_entity_id TEXT NOT NULL,
                sensor_entity_id TEXT, -- Optional moisture/EC sensor
                FOREIGN KEY (room_id) REFERENCES Rooms(id) ON DELETE CASCADE
            );",

            // Irrigation: Events (Timers)
            "CREATE TABLE IF NOT EXISTS IrrigationEvents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zone_id INTEGER NOT NULL,
                start_time TIME NOT NULL, -- Format: HH:MM
                duration_seconds INTEGER NOT NULL,
                days_of_week TEXT DEFAULT '1,2,3,4,5,6,7', -- 1=Mon, 7=Sun
                enabled INTEGER DEFAULT 1,
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
