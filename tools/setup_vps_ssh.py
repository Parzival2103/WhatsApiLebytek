#!/usr/bin/env python3
"""One-time: install local SSH public key on VPS. Password via VPS_SSH_PASSWORD env only."""
import os
import sys

import paramiko

HOST = os.environ.get("VPS_HOST", "2.24.197.198")
USER = os.environ.get("VPS_USER", "root")
PASSWORD = os.environ.get("VPS_SSH_PASSWORD", "")
KEY_PATH = os.path.expanduser(os.environ.get("VPS_SSH_KEY", "~/.ssh/lebytek_vps.pub"))
SSH_CONFIG = os.path.expanduser("~/.ssh/config")
HOST_ALIAS = "lebytek-vps"


def main() -> int:
    if not PASSWORD:
        print("Set VPS_SSH_PASSWORD env var", file=sys.stderr)
        return 1

    pub = open(KEY_PATH, encoding="utf-8").read().strip()
    fingerprint = pub.split()[1]

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, username=USER, password=PASSWORD, timeout=30)

    for cmd in (
        "mkdir -p ~/.ssh && chmod 700 ~/.ssh",
        f"grep -Fq '{fingerprint}' ~/.ssh/authorized_keys 2>/dev/null || echo '{pub}' >> ~/.ssh/authorized_keys",
        "chmod 600 ~/.ssh/authorized_keys",
    ):
        _, stdout, stderr = client.exec_command(cmd)
        if stdout.channel.recv_exit_status() != 0:
            print(stderr.read().decode(), file=sys.stderr)
            client.close()
            return 1

    client.close()
    print("authorized_keys updated on VPS")

    os.makedirs(os.path.dirname(SSH_CONFIG), exist_ok=True)
    block = f"""
Host {HOST_ALIAS}
    HostName {HOST}
    User {USER}
    IdentityFile {os.path.expanduser('~/.ssh/lebytek_vps')}
    IdentitiesOnly yes
"""
    existing = ""
    if os.path.exists(SSH_CONFIG):
        existing = open(SSH_CONFIG, encoding="utf-8").read()
    if f"Host {HOST_ALIAS}" not in existing:
        with open(SSH_CONFIG, "a", encoding="utf-8") as f:
            f.write(block.strip() + "\n")
        print(f"Added {HOST_ALIAS} to {SSH_CONFIG}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
