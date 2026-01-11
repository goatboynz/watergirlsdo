import sqlite3
import time
import datetime
import json
import os
import urllib.request
import threading

DB_PATH = '/www/public/waterdgirlsdo.db'
HA_URL = 'http://supervisor/core/api'
HA_TOKEN = os.environ.get('SUPERVISOR_TOKEN')

# Global locks to prevent overlapping zones in the same room
room_locks = {}
room_locks_mutex = threading.Lock()

def get_room_lock(room_id):
    global room_locks
    with room_locks_mutex:
        if room_id not in room_locks:
            room_locks[room_id] = threading.Lock()
        return room_locks[room_id]

def call_ha_service(entity_id, state):
    if not entity_id:
        return
    domain = entity_id.split('.')[0]
    service = 'turn_on' if state == 'on' else 'turn_off'
    url = f"{HA_URL}/services/{domain}/{service}"
    
    data = json.dumps({"entity_id": entity_id}).encode('utf-8')
    req = urllib.request.Request(url, data=data, method='POST')
    req.add_header('Authorization', f'Bearer {HA_TOKEN}')
    req.add_header('Content-Type', 'application/json')
    
    try:
        with urllib.request.urlopen(req) as f:
            print(f"[{datetime.datetime.now()}] HA API: {entity_id} -> {state}")
    except Exception as e:
        print(f"Error calling HA API for {entity_id}: {e}")

def run_irrigation(room_id, zone_id, pump_id, solenoid_id, duration):
    # Get the lock for this specific room to prevent simultaneous zone watering
    lock = get_room_lock(room_id)
    
    with lock:
        print(f"[{datetime.datetime.now()}] Starting Zone {zone_id} in Room {room_id}")
        
        # 1. Open Solenoid first if exists
        if solenoid_id:
            call_ha_service(solenoid_id, 'on')
            time.sleep(2) # Prime time
            
        # 2. Turn on Pump
        call_ha_service(pump_id, 'on')
        
        # 3. Wait for duration
        time.sleep(duration)
        
        # 4. Turn off Pump
        call_ha_service(pump_id, 'off')
        
        # 5. Close Solenoid last if exists
        if solenoid_id:
            time.sleep(2) # Pressure release
            call_ha_service(solenoid_id, 'off')
            
        # 6. Log to DB
        try:
            conn = sqlite3.connect(DB_PATH)
            cursor = conn.cursor()
            cursor.execute("INSERT INTO IrrigationLogs (zone_id, duration_seconds) VALUES (?, ?)", (zone_id, duration))
            conn.commit()
            conn.close()
        except Exception as e:
            print(f"Logging Error: {e}")

        print(f"[{datetime.datetime.now()}] Finished Zone {zone_id}")

def main():
    print("Waterd Girls Do - High Precision Scheduler Started")
    last_minute = -1
    
    while True:
        now = datetime.datetime.now()
        current_minute = now.minute
        
        if current_minute != last_minute:
            last_minute = current_minute
            current_time_str = now.strftime("%H:%M")
            current_day = str(now.isoweekday()) # 1=Mon, 7=Sun
            
            try:
                conn = sqlite3.connect(DB_PATH)
                cursor = conn.cursor()
                
                # Fetch events with room grouping info
                query = """
                    SELECT z.room_id, z.id, z.pump_entity_id, z.solenoid_entity_id, e.duration_seconds, e.days_of_week
                    FROM IrrigationEvents e
                    JOIN Zones z ON e.zone_id = z.id
                    WHERE e.enabled = 1 AND e.start_time = ?
                """
                cursor.execute(query, (current_time_str,))
                events = cursor.fetchall()
                conn.close()
                
                for room_id, zone_id, pump_id, solenoid_id, duration, days_of_week in events:
                    if current_day in days_of_week.split(','):
                        # Use a thread for each event. The Room Lock inside the thread 
                        # will ensure they run sequentially if they belong to the same room.
                        thread = threading.Thread(target=run_irrigation, args=(room_id, zone_id, pump_id, solenoid_id, duration))
                        thread.start()
                        
            except Exception as e:
                print(f"Scheduler Error: {e}")
                
        time.sleep(5) 

if __name__ == "__main__":
    main()
