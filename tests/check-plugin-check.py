"""Fail closed on checker failures, findings, empty or unknown output."""
import re
import sys
from pathlib import Path


def clean_result(text, status):
    if status != 0:
        return False
    text = re.sub(r"\x1b\[[0-9;]*m", "", text)
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    success = "Success: Checks complete. No errors found."
    if lines.count(success) != 1:
        return False
    return not any(re.match(r"(?:Error:|Warning:|FILE:|\[|\{)", line) for line in lines)


if __name__ == "__main__":
    if not clean_result(Path(sys.argv[1]).read_text(), int(sys.argv[2])):
        sys.exit("Plugin Check failed, produced findings or did not report successful completion.")
    print("Plugin Check completed successfully without errors or warnings.")
