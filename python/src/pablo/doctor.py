"""CLI preflight checks — ``pablo doctor`` / ``/pablo-doctor``.

A Python module (decided 2026-07-26): derives the required CLI set from the
configured projects. ``gh`` is always required (every project's PR/CI flow
goes through GitHub); the Atlassian CLI ``acli`` (Jira) — and a separate
``acli-confluence`` probe when some project configures a Confluence space —
only for jira projects; ``linear`` only when a project uses that provider;
``orca``/``opencode`` always (the agent-runner path). Each CLI is checked
for being installed AND authenticated/ready, surfacing the CLI's own
instructions on failure. Non-zero exit when anything required is missing —
the dispatcher uses the same check as its fail-fast guard.
"""

from __future__ import annotations

import shutil
import subprocess
from dataclasses import dataclass

from pablo.config import ProjectConfig

# cli name → (probe argv, login hint shown on failure). The probe argv's
# first element is also the binary name used for the PATH check.
CLI_PROBES: dict[str, tuple[list[str], str]] = {
    "gh": (["gh", "auth", "status"], "run: gh auth login"),
    "acli": (["acli", "jira", "auth", "status"], "run: acli auth login"),
    "acli-confluence": (
        ["acli", "confluence", "auth", "status"],
        "run: acli confluence auth login",
    ),
    "linear": (["linear", "auth", "status"], "run: linear auth login (schpet/linear-cli)"),
    "orca": (["orca", "status"], "start the Orca app, or run: orca open"),
    "opencode": (["opencode", "--version"], "install opencode: https://opencode.ai"),
}

ALWAYS_REQUIRED = ["gh", "opencode", "orca"]


@dataclass
class CheckResult:
    cli: str
    installed: bool
    authenticated: bool
    detail: str
    hint: str

    @property
    def ok(self) -> bool:
        return self.installed and self.authenticated


def required_clis(projects: dict[str, ProjectConfig]) -> list[str]:
    required = set(ALWAYS_REQUIRED)
    needs_confluence = False
    for cfg in projects.values():
        if cfg.provider == "jira":
            required.add("acli")
            if cfg.confluence_space:
                needs_confluence = True
        elif cfg.provider == "linear":
            required.add("linear")
    if needs_confluence:
        required.add("acli-confluence")
    return sorted(required)


PROBE_TIMEOUT_S = 30


def _probe(argv: list[str]) -> tuple[int, str]:
    try:
        proc = subprocess.run(
            argv,
            capture_output=True,
            text=True,
            timeout=PROBE_TIMEOUT_S,
            stdin=subprocess.DEVNULL,
        )
    except subprocess.TimeoutExpired:
        return 124, f"timed out after {PROBE_TIMEOUT_S}s"
    output = (proc.stderr.strip() + "\n" + proc.stdout.strip()).strip()
    return proc.returncode, output


def _binary_for(cli_name: str) -> str:
    """The PATH binary to look up for a check — the probe's first argv
    element is always the binary name (acli appears in two probes)."""
    return CLI_PROBES[cli_name][0][0]


def check_all(projects: dict[str, ProjectConfig]) -> list[CheckResult]:
    results = []
    for cli_name in required_clis(projects):
        probe_argv, hint = CLI_PROBES[cli_name]
        if shutil.which(_binary_for(cli_name)) is None:
            results.append(
                CheckResult(
                    cli=cli_name,
                    installed=False,
                    authenticated=False,
                    detail=f"{cli_name} is not installed (not on PATH)",
                    hint=hint,
                )
            )
            continue
        code, output = _probe(probe_argv)
        results.append(
            CheckResult(
                cli=cli_name,
                installed=True,
                authenticated=code == 0,
                detail=output.splitlines()[0] if output else ("ok" if code == 0 else "failed"),
                hint=hint if code != 0 else "",
            )
        )
    return results


def render(results: list[CheckResult]) -> str:
    lines = []
    for result in results:
        icon = "✅" if result.ok else "❌"
        lines.append(f"{icon} {result.cli:<10} {result.detail}")
        if not result.ok and result.hint:
            lines.append(f"   → {result.hint}")
    return "\n".join(lines)
