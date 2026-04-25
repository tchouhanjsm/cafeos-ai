import subprocess
from tools.cli.utils.logger import log_event


def doctor():

    print("\n=== CafeOS Dev Doctor ===\n")

    result_output = ""

    # Step 1: Lint check
    result_output += "[1] Running lint check...\n"
    res1 = subprocess.run(["ruff", "check", "."], capture_output=True, text=True)
    result_output += res1.stdout + "\n"

    # Step 2: Auto-fix
    result_output += "[2] Auto-fixing issues...\n"
    res2 = subprocess.run(
        ["ruff", "check", ".", "--fix"], capture_output=True, text=True
    )
    result_output += res2.stdout + "\n"

    # Step 3: Format
    result_output += "[3] Formatting code...\n"
    res3 = subprocess.run(["black", "."], capture_output=True, text=True)
    result_output += res3.stdout + "\n"

    result_output += "\n=== Dev Doctor Complete ===\n"

    print(result_output)
    log_event("dev.log", "Dev Doctor run completed", "DEV")
    log_event("dev.log", result_output[:500])  # store partial output
    return result_output
