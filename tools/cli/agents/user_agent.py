import requests
import json
import os

BASE_URL = "http://127.0.0.1:8000"
STATE_FILE = "logs/state.json"


# ---------------- STATE ----------------


def load_state():
    if not os.path.exists(STATE_FILE):
        return {}
    with open(STATE_FILE, "r") as f:
        return json.load(f)


def save_state(state):
    with open(STATE_FILE, "w") as f:
        json.dump(state, f)


# ---------------- USERS ----------------


def user_stats():
    res = requests.get(f"{BASE_URL}/users")
    users = res.json()

    if not users:
        print("No users found.")
        return

    print(f"Total users: {len(users)}")
    latest = users[-1]
    print(f"Latest user: {latest['name']} ({latest['email']})")


def find_duplicates():
    res = requests.get(f"{BASE_URL}/users")
    users = res.json()

    email_count = {}
    for u in users:
        email = u["email"]
        email_count[email] = email_count.get(email, 0) + 1

    duplicates = {k: v for k, v in email_count.items() if v > 1}

    if not duplicates:
        print("No duplicate emails found.")
        return

    print("Duplicate emails found:")
    for email, count in duplicates.items():
        print(f"- {email} ({count} users)")


def user_report():
    res = requests.get(f"{BASE_URL}/users")
    users = res.json()

    if not users:
        print("No users found.")
        return

    emails = [u["email"] for u in users]

    print("\n--- CafeOS User Report ---")
    print(f"Total users: {len(users)}")
    print(f"Unique emails: {len(set(emails))}")
    print(f"Duplicate emails: {len(emails) - len(set(emails))}")

    latest = users[-1]
    print(f"Latest user: {latest['name']} ({latest['email']})")


# ---------------- SALES ----------------


def sales_summary():
    res = requests.get(f"{BASE_URL}/order-items")
    items = res.json()

    if not isinstance(items, list) or not items:
        print("No order data found.")
        return

    total_orders = len(set(i.get("order_id") for i in items))
    total_revenue = sum(
        float(i.get("unit_price", 0)) * i.get("quantity", 0) for i in items
    )

    item_count = {}
    for i in items:
        name = i.get("item_name", "unknown")
        item_count[name] = item_count.get(name, 0) + i.get("quantity", 0)

    top_item = max(item_count, key=item_count.get)

    print("\n--- CafeOS Sales Summary ---")
    print(f"Total orders: {total_orders}")
    print(f"Total revenue: {total_revenue}")
    print(f"Top item: {top_item}")


def revenue_today():
    res = requests.get(f"{BASE_URL}/analytics/today")
    items = res.json()

    if not items:
        print("No orders today.")
        return

    total_orders = len(set(i["order_id"] for i in items))
    total_revenue = sum(float(i["unit_price"]) * i["quantity"] for i in items)

    print("\n--- Today's Revenue ---")
    print(f"Orders today: {total_orders}")
    print(f"Revenue today: {total_revenue}")


# ---------------- KITCHEN ----------------


def get_kitchen_data():
    res = requests.get(f"{BASE_URL}/order-items")
    items = res.json()

    pending = [
        i for i in items if i.get("status", "").lower() not in ["served", "completed"]
    ]
    order_ids = set(i["order_id"] for i in pending)

    return {"pending_items": len(pending), "orders_in_progress": len(order_ids)}


# ---------------- ALERTS (CORE LOGIC) ----------------


def get_alerts_data():
    alerts_list = []

    # Kitchen
    kitchen = get_kitchen_data()
    if kitchen["pending_items"] > 10:
        alerts_list.append(
            {
                "type": "warning",
                "message": f"High kitchen load: {kitchen['pending_items']} pending items",
            }
        )

    # Users
    res = requests.get(f"{BASE_URL}/users")
    users = res.json()

    emails = [u["email"] for u in users]
    duplicates = len(emails) - len(set(emails))

    if duplicates > 0:
        alerts_list.append(
            {"type": "warning", "message": f"Duplicate users: {duplicates}"}
        )

    # Revenue today
    try:
        res = requests.get(f"{BASE_URL}/analytics/today")
        today_items = res.json()

        if isinstance(today_items, list) and len(today_items) < 2:
            alerts_list.append({"type": "info", "message": "Low activity today"})
    except:
        pass

    return alerts_list


# ---------------- ALERTS (CLI) ----------------


def alerts():
    alerts_data = get_alerts_data()

    print("\n--- CafeOS Alerts ---")

    if not alerts_data:
        print("✅ All systems running normally")
        return

    for a in alerts_data:
        print(f"⚠️ {a['message']}")

    print(f"\nTotal issues detected: {len(alerts_data)}")


# ---------------- CLEANUP ----------------


def cleanup_users():
    print("\n--- Cleanup Users ---")

    res = requests.get(f"{BASE_URL}/users")
    users = res.json()

    seen = set()
    duplicates = []

    for u in users:
        if u["email"] in seen:
            duplicates.append(u["id"])
        else:
            seen.add(u["email"])

    if not duplicates:
        print("No duplicate users found.")
        return

    for user_id in duplicates:
        requests.delete(f"{BASE_URL}/users/{user_id}")

    print(f"Removed {len(duplicates)} duplicate users")


def get_kitchen_data():
    import requests

    BASE_URL = "http://127.0.0.1:8000"

    res = requests.get(f"{BASE_URL}/order-items")
    items = res.json()

    pending = [
        i for i in items if i.get("status", "").lower() not in ["served", "completed"]
    ]

    order_ids = set(i["order_id"] for i in pending)

    return {"pending_items": len(pending), "orders_in_progress": len(order_ids)}


def get_sales_data():
    import requests

    BASE_URL = "http://127.0.0.1:8000"

    res = requests.get(f"{BASE_URL}/order-items")
    items = res.json()

    revenue = 0
    for i in items:
        revenue += float(i.get("unit_price", 0)) * i.get("quantity", 0)

    return {"total_revenue": revenue, "total_items": len(items)}
