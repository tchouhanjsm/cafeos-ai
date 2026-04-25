import sys
import requests
import os
import time
from tools.cli.agents.dev_agent import doctor
from tools.cli.utils.logger import log_event


from tools.cli.agents.user_agent import (
    user_stats,
    find_duplicates,
    user_report,
    sales_summary,
    alerts,
    cleanup_users,
)

BASE_URL = "http://127.0.0.1:8000"


# ---------------- USER COMMANDS ----------------


def create_user(name, email):
    res = requests.post(f"{BASE_URL}/users", json={"name": name, "email": email})
    print(res.json())


def list_users():
    res = requests.get(f"{BASE_URL}/users")
    users = res.json()

    if not users:
        print("No users found.")
        return

    for u in users:
        print(f"{u['id']}: {u['name']} ({u['email']})")


# ---------------- MAIN CLI ----------------
def auto_fix():
    print("\n=== CafeOS Auto-Fix ===")
    print("DEBUG: auto_fix running")

    from tools.cli.agents.user_agent import alerts, cleanup_users

    print("\n[1] Checking system...")
    log_event("alerts.log", "Running alerts check")
    alerts()

    print("\n[2] Fixing known issues...")
    log_event("fixes.log", "Running cleanup_users")
    cleanup_users()

    print("\n[3] Re-checking system...")
    log_event("alerts.log", "Re-check after fixes")
    alerts()

    print("\n=== Auto-Fix Complete ===")
    log_event("fixes.log", "Auto-fix started", "FIX")
    log_event("fixes.log", "Auto-fix completed", "FIX")


def show_logs():
    print("\n--- CafeOS Logs ---")

    try:
        print("\n[Alerts]")
        with open("logs/alerts.log", "r") as f:
            print(f.read())
    except FileNotFoundError:
        print("No alerts log found.")

    try:
        print("\n[Fixes]")
        with open("logs/fixes.log", "r") as f:
            print(f.read())
    except FileNotFoundError:
        print("No fixes log found.")


def tail_logs():
    print("\n--- Live CafeOS Logs (CTRL+C to exit) ---\n")

    files = ["logs/alerts.log", "logs/fixes.log"]

    # keep track of file positions
    positions = {}

    for file in files:
        try:
            with open(file, "r") as f:
                f.seek(0, 2)  # move to end
                positions[file] = f.tell()
        except FileNotFoundError:
            positions[file] = 0

    while True:
        for file in files:
            try:
                with open(file, "r") as f:
                    f.seek(positions[file])
                    new_data = f.read()

                    if new_data:
                        print(f"\n[{file}]")
                        print(new_data.strip())

                    positions[file] = f.tell()
            except FileNotFoundError:
                continue

        time.sleep(2)


# main is here --


def main():
    if len(sys.argv) < 2:
        print("Usage:")
        print("  dev user create <name> <email>")
        print("  dev user list")
        print("  dev run api")
        print("  dev agent stats")
        print("  dev agent duplicates")
        return

    resource = sys.argv[1]

    # -------- USER --------
    if resource == "user":
        if len(sys.argv) < 3:
            print("Missing user action")
            return

        action = sys.argv[2]

        if action == "create":
            name = sys.argv[3]
            email = sys.argv[4]
            create_user(name, email)

        elif action == "list":
            list_users()

        else:
            print("Unknown user action")

    # -------- RUN --------
    elif resource == "run":
        if len(sys.argv) < 3:
            print("Missing run target")
            return

        action = sys.argv[2]

        if action == "api":
            os.system("uvicorn apps.api.main:app --reload")
        else:
            print("Unknown run command")
    elif resource == "watch":
        if len(sys.argv) < 3:
            print("Missing watch command")
            return

        action = sys.argv[2]

        import time

        if action == "auto-fix":
            print("Starting CafeOS auto-fix watcher...\n")

            while True:
                try:
                    auto_fix()
                except Exception as e:
                    print(f"Error: {e}")

                print("\n--- waiting 15 seconds ---\n")
                time.sleep(15)

        else:
            print("Unknown watch command")
    # -------- AGENT --------
    elif resource == "agent":
        if len(sys.argv) < 3:
            print("Missing agent command")
            return

        action = sys.argv[2]

        if action == "stats":
            user_stats()

        elif action == "duplicates":
            find_duplicates()

        elif action == "report":
            user_report()
        elif action == "sales":
            sales_summary()
        elif action == "kitchen":
            kitchen_load()
        elif action == "revenue-today":
            revenue_today()
        elif action == "alerts":
            alerts()
        elif action == "cleanup-users":
            cleanup_users()
        elif action == "doctor":
            doctor()

        else:
            print("Unknown agent command")
    elif resource == "logs":
        if len(sys.argv) < 3:
            print("Missing logs command")
            return

        action = sys.argv[2]

        if action == "show":
            show_logs()

        elif action == "tail":
            tail_logs()

        else:
            print("Unknown logs command")


if __name__ == "__main__":
    main()
