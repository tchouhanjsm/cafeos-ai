from fastapi import APIRouter
from fastapi.responses import HTMLResponse
from tools.cli.agents.user_agent import (
    get_alerts_data,
    get_kitchen_data,
    get_sales_data,
    cleanup_users,
)
from tools.cli.dev import auto_fix
from tools.cli.agents.dev_agent import doctor
from tools.cli.utils.logger import read_logs
import io
import sys

router = APIRouter()


@router.get("/dashboard", response_class=HTMLResponse)
def dashboard():
    alerts = get_alerts_data()
    kitchen = get_kitchen_data()
    sales = get_sales_data()

    dev_logs = read_logs("dev.log", 10)
    fix_logs = read_logs("fixes.log", 10)

    # -------- History Parsing --------
    history_items = []

    for line in dev_logs + fix_logs:
        line = line.strip()
        if not line:
            continue

        try:
            time_part = line.split("]")[0].replace("[", "")
            type_part = line.split("]")[1].replace("[", "").strip()
            message = "]".join(line.split("]")[2:]).strip()

            history_items.append(
                {"time": time_part, "type": type_part, "message": message}
            )
        except:
            continue

    history_html = ""
    for item in reversed(history_items):
        color = "#333"

        if item["type"] == "DEV":
            color = "#28a745"
        elif item["type"] == "FIX":
            color = "#007bff"

        history_html += f"""
        <div style="margin-bottom:10px; padding-bottom:5px; border-bottom:1px solid #eee;">
            <span style="color:{color}; font-weight:bold;">
                {item['type']}
            </span>
            <span style="color:gray;">[{item['time']}]</span>
            <br>
            {item['message']}
        </div>
        """

    # -------- Alerts --------
    alerts_html = ""
    has_duplicates = False

    for a in alerts:
        alerts_html += f"<div class='alert'>{a['message']}</div>"
        if "Duplicate users" in a["message"]:
            has_duplicates = True

    button_html = ""
    if has_duplicates:
        button_html = """
        <button onclick="cleanupUsers()">Fix Duplicate Users</button>
        """

    # -------- System Health --------
    health_score = 100

    health_score -= len(alerts) * 10

    if kitchen["pending_items"] > 10:
        health_score -= 20

    health_score -= min(len(fix_logs) * 2, 20)

    health_score = max(0, health_score)

    if health_score > 80:
        health_color = "green"
    elif health_score > 50:
        health_color = "orange"
    else:
        health_color = "red"

    html = f"""
    <html>
    <head>
        <title>CafeOS Dashboard</title>
        <meta http-equiv="refresh" content="15">

        <style>
            body {{
                font-family: Arial;
                padding: 20px;
                background: #f5f5f5;
            }}

            .card {{
                background: white;
                padding: 20px;
                margin-bottom: 20px;
                border-radius: 10px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }}

            .alert {{
                color: red;
                font-weight: bold;
                margin: 5px 0;
            }}

            button {{
                padding: 10px 15px;
                border: none;
                border-radius: 5px;
                background: #ff4d4d;
                color: white;
                cursor: pointer;
                margin-top: 10px;
            }}

            button:hover {{
                background: #e60000;
            }}
        </style>

<script>
function cleanupUsers() {{
    if (!confirm("Remove duplicate users?")) return;

    fetch('/actions/cleanup-users', {{
        method: 'POST'
    }}).then(() => {{
        location.reload();
    }});
}}

function runAutoFix() {{
    if (!confirm("Run auto-fix?")) return;

    const box = document.getElementById("autoFixOutput");
    box.innerText = "Running...";

    fetch('/actions/auto-fix', {{
        method: 'POST'
    }})
    .then(res => res.json())
    .then(data => {{
        box.innerText = data.output;
    }});
}}

function runDevDoctor() {{
    if (!confirm("Run Dev Doctor?")) return;

    const box = document.getElementById("devDoctorOutput");
    box.innerText = "Running...";

    fetch('/actions/dev-doctor', {{
        method: 'POST'
    }})
    .then(res => res.json())
    .then(data => {{
        box.innerText = data.output;
    }});
}}
</script>

    </head>

    <body>

        <h1>☕ CafeOS Dashboard</h1>

        <div class="card">
            <h2>🧠 System Health</h2>
            <h1 style="color:{health_color};">{health_score}%</h1>
        </div>

        <div class="card">
            <h2>🚨 Alerts</h2>
            {alerts_html if alerts_html else "No issues"}
            <br>
            {button_html}
            <br><br>
            <button onclick="runAutoFix()" style="background:#007bff;">Run Auto-Fix</button>
        </div>

        <div class="card">
            <h2>⚙️ Auto-Fix Output</h2>
            <pre id="autoFixOutput">No runs yet</pre>
        </div>

        <div class="card">
            <h2>🍳 Kitchen</h2>
            <p>Pending items: {kitchen['pending_items']}</p>
            <p>Orders in progress: {kitchen['orders_in_progress']}</p>
        </div>

        <div class="card">
            <h2>💰 Sales</h2>
            <p>Total revenue: {sales['total_revenue']}</p>
            <p>Total items sold: {sales['total_items']}</p>
        </div>

        <div class="card">
            <h2>🧠 Dev Doctor</h2>
            <button onclick="runDevDoctor()" style="background:#28a745;">Run Dev Doctor</button>
            <pre id="devDoctorOutput">No runs yet</pre>
        </div>

        <div class="card">
            <h2>📜 System History</h2>
            {history_html}
        </div>

    </body>
    </html>
    """

    return html


# -------- ACTION ROUTES --------


@router.post("/actions/cleanup-users")
def cleanup_users_action():
    cleanup_users()
    return {"status": "success"}


@router.post("/actions/auto-fix")
def auto_fix_action():
    buffer = io.StringIO()
    sys.stdout = buffer

    auto_fix()

    sys.stdout = sys.__stdout__

    return {"status": "success", "output": buffer.getvalue()}


@router.post("/actions/dev-doctor")
def dev_doctor_action():
    buffer = io.StringIO()
    sys.stdout = buffer

    output = doctor()

    sys.stdout = sys.__stdout__

    return {"status": "success", "output": output}
