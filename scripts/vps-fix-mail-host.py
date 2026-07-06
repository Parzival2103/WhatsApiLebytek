#!/usr/bin/env python3
from pathlib import Path

env_path = Path("/home/lebytek/htdocs/lebytek.com/.env")
updates = {
    "MAIL_HOST": "moluk-new.hosting-mexico.net",
    "MAIL_PORT": "587",
    "MAIL_ENCRYPTION": "tls",
}

lines = []
seen = set()
for line in env_path.read_text().splitlines():
    key = line.split("=", 1)[0] if "=" in line and not line.lstrip().startswith("#") else None
    if key in updates:
        lines.append(f"{key}={updates[key]}")
        seen.add(key)
    else:
        lines.append(line)

env_path.write_text("\n".join(lines) + "\n")
print("Updated:", ", ".join(f"{k}={updates[k]}" for k in updates))
