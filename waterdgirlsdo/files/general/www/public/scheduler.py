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

def call_ha_service(entity_id, state):
    domain = entity_id.split('.')[0]
    service = 'turn_on' if state == 'on' else 'turn_off'
    url = f"{HA_URL}/services/{domain}/{service}"
    
    data = json.dumps({"entity_id": entity_id}).encode('utf-8')
    req = urllib.request.Request(url, data=data, method='POST')
    req.add_header('Authorization', f'Bearer {HA_TOKEN}')
    req.add_header('Content-Type', 'application/json')
    
    try:
        with urllib.request.urlopen(req) as f:
            print(f"HA API Response: {f.status} for {entity_id} {state}")
    except Exception as e:
        print(f"Error calling HA API for {entity_id}: {e}")

def run_irrigation(zone_id, entity_id, duration):
    print(f"Starting irrigation for Zone {zone_id} ({entity_id}) for {duration}s")
    call_ha_service(entity_id, 'on')
    time.sleep(duration)
    call_ha_service(entity_id, 'off')
    print(f"Finished irrigation for Zone {zone_id}")

def main():
    print("Irrigation Scheduler Started")
    last_minute = -1
    
    while True:
        now = datetime.datetime.now()
        current_minute = now.minute
        
        # Only check once per minute
        if current_minute != last_minute:
            last_minute = current_minute
            current_time_str = now.strftime("%H:%M")
            current_day = str(now.isoweekday()) # 1=Mon, 7=Sun
            
            try:
                conn = sqlite3.connect(DB_PATH)
                cursor = conn.cursor()
                
                # Fetch events that should start now
                query = """
                    SELECT e.id, z.switch_entity_id, e.duration_seconds, e.days_of_week
                    FROM IrrigationEvents e
                    JOIN Zones z ON e.zone_id = z.id
                    WHERE e.enabled = 1 AND e.start_time = ?
                """
                cursor.execute(query, (current_time_str,))
                events = cursor.fetchall()
                conn.close()
                
                for event_id, entity_id, duration, days_of_week in events:
                    if current_day in days_of_week.split(','):
                        # Run in a separate thread so multiple zones can run at once
                        thread = threading.Thread(target=run_irrigation, args=(event_id, entity_id, duration))
                        thread.start()
                        
            except Exception as e:
                print(f"Scheduler DB Error: {e}")
                
        time.sleep(10) # Wake up every 10 seconds to check for minute change

if __name__ == "__main__":
    main()
