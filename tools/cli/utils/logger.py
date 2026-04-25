from datetime import datetime


def log_event(filename, message, log_type="INFO"):

    timestamp = datetime.now().strftime("%H:%M:%S")

    with open(f"logs/{filename}", "a") as f:
        f.write(f"[{timestamp}] [{log_type}] {message}\n")


def read_logs(filename, lines=10):
    try:
        with open(f"logs/{filename}", "r") as f:
            all_lines = f.readlines()
            return all_lines[-lines:]
    except FileNotFoundError:
        return []
