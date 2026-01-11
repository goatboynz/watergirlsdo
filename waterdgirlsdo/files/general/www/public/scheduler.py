import sqlite3
import time
import datetime
import json
import os
import urllib.request
import threading
import sys

DB_PATH = '/data/waterdgirlsdo.db'
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
            pass # print(f"[{datetime.datetime.now()}] HA API: {entity_id} -> {state}")
    except Exception as e:
        print(f"Error calling HA API for {entity_id}: {e}")

def run_irrigation(room_id, zone_id, pump_id, solenoid_id, duration, event_type="P1"):
    lock = get_room_lock(room_id)
    
    with lock:
        now_start = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        print(f"[{now_start}] Starting Zone {zone_id} in Room {room_id} for {duration}s")
        
        # 1. Open Solenoid first if exists
        if solenoid_id:
            call_ha_service(solenoid_id, 'on')
            time.sleep(2) 
            
        # 2. Turn on Pump
        call_ha_service(pump_id, 'on')
        
        # 3. Wait for duration
        time.sleep(duration)
        
        # 4. Turn off Pump
        call_ha_service(pump_id, 'off')
        
        # 5. Close Solenoid last if exists
        if solenoid_id:
            time.sleep(2) 
            call_ha_service(solenoid_id, 'off')
            
        # 6. Calculate Volume and Log
        try:
            conn = sqlite3.connect(DB_PATH)
            cur = conn.cursor()
            cur.execute("SELECT plants_count, drippers_per_plant, dripper_flow_rate FROM Zones WHERE id = ?", (zone_id,))
            zinfo = cur.fetchone()
            volume_ml = 0
            if zinfo:
                plants, drippers, flow_rate = zinfo
                # flow_rate is mL/hour. duration is seconds.
                total_flow_rate = plants * drippers * flow_rate
                volume_ml = (total_flow_rate / 3600) * duration
                
            cur.execute("INSERT INTO IrrigationLogs (zone_id, event_type, start_time, duration_seconds, volume_ml) VALUES (?, ?, ?, ?, ?)", 
                       (zone_id, event_type, now_start, duration, volume_ml))
            conn.commit()
            conn.close()
        except Exception as e:
            print(f"Logging Error: {e}")

        print(f"[{datetime.datetime.now()}] Finished Zone {zone_id}")

def main():
    print(f"[{datetime.datetime.now()}] Waterd Girls Do - Scheduler Initializing")
    
    # NTP / Time Check
    # In Home Assistant addons, time is usually managed by the host. 
    # We just log it for verification.
    print(f"SYSTEM TIME: {datetime.datetime.now()}")
    print(f"TIMEZONE: {time.tzname}")
    
    last_minute = -1
    
    while True:
        now = datetime.datetime.now()
        current_minute = (now.hour * 60) + now.minute
        
        if current_minute != last_minute:
            last_minute = current_minute
            current_time_str = now.strftime("%H:%M")
            current_day = str(now.isoweekday()) 
            
            try:
                conn = sqlite3.connect(DB_PATH)
                cursor = conn.cursor()
                
                query = """
                    SELECT z.room_id, z.id, z.pump_entity_id, z.solenoid_entity_id, e.duration_seconds, e.days_of_week, e.event_type
                    FROM IrrigationEvents e
                    JOIN Zones z ON e.zone_id = z.id
                    WHERE e.enabled = 1 AND e.start_time = ?
                """
                cursor.execute(query, (current_time_str,))
                events = cursor.fetchall()
                conn.close()
                
                for room_id, zone_id, pump_id, solenoid_id, duration, days_of_week, event_type in events:
                    if current_day in days_of_week.split(','):
                        thread = threading.Thread(target=run_irrigation, args=(room_id, zone_id, pump_id, solenoid_id, duration, event_type))
                        thread.start()
                        
            except Exception as e:
                print(f"Scheduler Error: {e}")
                
        time.sleep(5) 

if __name__ == "__main__":
    main()
