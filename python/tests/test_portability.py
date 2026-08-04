"""Tripwire: the engine must stay OS-portable (Linux + macOS).

Platform-specific machinery belongs in bin/install.sh, systemd/ and
launchd/ — never in src/pablo/. This guard fails the suite if a
Linux-only dependency sneaks into the engine.
"""

from pathlib import Path

FORBIDDEN = ("systemctl", "journalctl", "/etc/", "/proc/", "/opt/")

SRC = Path(__file__).resolve().parents[1] / "src" / "pablo"


def test_engine_has_no_platform_specific_references():
    offenders = []
    for path in sorted(SRC.rglob("*.py")):
        text = path.read_text()
        for needle in FORBIDDEN:
            if needle in text:
                offenders.append(f"{path.name}: {needle}")
    assert not offenders, (
        "platform-specific references in the engine (move them to the "
        f"install layer): {offenders}"
    )
