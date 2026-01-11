# WaterdGirlsDo - Irrigation Controller

WaterdGirlsDo is a specialized Home Assistant addon for precise irrigation management. It allows users to define rooms and zones, and schedule irrigation events using Home Assistant entities.

## 🚀 Features

- **Room & Zone Management**: Organize your grow space into logical rooms and specific irrigation zones.
- **Home Assistant Integration**: Directly control your existing HA switches, lights, or outlets.
- **Precision Scheduling**: Set start times and durations (in seconds) for each zone.
- **Day-of-Week Control**: Choose exactly which days each schedule should run.
- **Background Scheduler**: A robust Python service that monitors the database and triggers events precisely.

## 🛠️ Installation

1. Go to Home Assistant **Settings** -> **Add-ons** -> **Add-on Store**.
2. Click the 3-dot menu and choose **Repositories**.
3. Add your repository URL containing this addon.
4. Search for "**Waterd Girls Do**" and click **Install**.
5. Enable "**Show in sidebar**" for easy access.
6. Click **Start**.

## 🔌 Requirements

- Access to the Home Assistant Supervisor API (enabled by default in this addon).
- Switch entities already configured in Home Assistant.

## 📖 Usage

1. Open the **Waterd Girls Do** Web UI.
2. Go to the **Irrigation Control** tab.
3. Add a **Room**.
4. Add a **Zone**, selecting the corresponding Home Assistant switch entity.
5. Create an **Irrigation Event** with your desired start time, duration, and days of the week.
6. Ensure the event is "**Enabled**".

---
*Take control of your garden with precision.*
