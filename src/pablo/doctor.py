"""CLI preflight checks — ``pablo doctor`` / ``/pablo-doctor``.

Per the (amended) spec this is a Python module, not a bash script. It
derives the required CLI set from the configured projects: ``gh`` is always
required (every project's PR/CI flow goes through GitHub), ``jira`` and
``linear`` only when a project uses that provider, plus ``orca`` and
``opencode`` (the agent-runner path). Each CLI is checked for being
installed AND authenticated/ready, surfacing the CLI's own instructions on
failure. Non-zero exit when anything required is missing — the dispatcher
uses the same check as its fail-fast guard.
"""

from __future__ import annotations

import shutil
import subprocess
from dataclasses import dataclass

from pablo import mcpclient
from pablo.config import ProjectConfig

# cli name → (probe argv, login hint shown on failure)
CLI_PROBES: dict[str, tuple[list[str], str]] = {
    "gh": (["gh", "auth", "status"], "run: gh auth login"),
    "linear": (["linear", "auth", "status"], "run: linear auth login (schpet/linear-cli)"),
    "orca": (["orca", "status"], "start the Orca app, or run: orca open"),
    "opencode": (["opencode", "--version"], "install opencode: https://opencode.ai"),
}

# Jira goes through the Atlassian MCP (spec amendment 2026-07-26), so its
# check is an MCP reachability/auth check, not a CLI probe.
JIRA_MCP_CHECK = "jira-mcp"
JIRA_MCP_AUTH_HINT = (
    f"run once: npx -y mcp-remote {mcpclient.ATLASSIAN_MCP_URL} "
    "(completes the Atlassian browser login; tokens cached by mcp-remote)"
)

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
    for cfg in projects.values():
        if cfg.provider == "jira":
            required.add(JIRA_MCP_CHECK)
        elif cfg.provider == "linear":
            required.add("linear")
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


def _mcp_userinfo() -> dict:
    """Module-level for test patching; only reached with cached auth."""
    from pablo.providers.jira import call

    return call("atlassianUserInfo", {})


def _check_jira_mcp() -> CheckResult:
    try:
        mcpclient.npx_path()  # PATH or nvm installs, newest Node, >= 18
    except Exception as exc:
        return CheckResult(
            cli=JIRA_MCP_CHECK,
            installed=False,
            authenticated=False,
            detail=str(exc),
            hint="install Node.js >= 18 (provides npx, which runs the mcp-remote bridge)",
        )
    # Never spawn the bridge without cached auth: it would start the OAuth
    # browser flow — a hang under the systemd timer. Fail fast instead.
    if not mcpclient.auth_cache_present():
        return CheckResult(
            cli=JIRA_MCP_CHECK,
            installed=True,
            authenticated=False,
            detail="no cached Atlassian MCP auth (~/.mcp-auth)",
            hint=JIRA_MCP_AUTH_HINT,
        )
    try:
        info = _mcp_userinfo()
    except Exception as exc:
        return CheckResult(
            cli=JIRA_MCP_CHECK,
            installed=True,
            authenticated=False,
            detail=str(exc).splitlines()[0],
            hint=JIRA_MCP_AUTH_HINT,
        )
    account = ""
    if isinstance(info, dict):
        account = info.get("email") or info.get("name") or ""
    return CheckResult(
        cli=JIRA_MCP_CHECK,
        installed=True,
        authenticated=True,
        detail=f"atlassian mcp authenticated{f' as {account}' if account else ''}",
        hint="",
    )


def check_all(projects: dict[str, ProjectConfig]) -> list[CheckResult]:
    results = []
    for cli_name in required_clis(projects):
        if cli_name == JIRA_MCP_CHECK:
            results.append(_check_jira_mcp())
            continue
        probe_argv, hint = CLI_PROBES[cli_name]
        if shutil.which(cli_name) is None:
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
