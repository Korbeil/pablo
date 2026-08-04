import subprocess
from dataclasses import dataclass
from pathlib import Path

import pytest

from pablo import agents


@pytest.fixture(autouse=True)
def _no_real_agent_processes(request, monkeypatch):
    """Safety net: no test may spawn a real opencode/orca agent run.

    State-machine on-enter actions launch agents; a test that forgets to
    stub `agents.launch` would silently start a real `opencode run` (a real
    LLM call). Stub them all by default; tests assert against their own
    stubs, which override these. test_agents.py is exempt — it tests the
    real implementations against faked subprocess/run_cli.
    """
    if request.module.__name__.endswith("test_agents"):
        yield
        return
    monkeypatch.setattr(agents, "launch", lambda wt, agent, prompt, project, branch: "stub-handle")
    monkeypatch.setattr(agents, "run_startup_script", lambda wt, script, project, branch: "stub-handle")
    monkeypatch.setattr(agents, "spawn_watcher", lambda *a, **k: None)
    monkeypatch.setattr(agents, "active_sessions", lambda wt: [])
    monkeypatch.setattr(agents, "bulk_active_sessions", lambda worktrees: {})
    monkeypatch.setattr(agents, "set_worktree_display_name", lambda wt, name, issue_number=None: None)
    monkeypatch.setattr(agents, "_launch_headless", lambda wt, agent, prompt: "pid:99999")
    yield


def run(cwd: Path, *args: str) -> str:
    return subprocess.run(
        args, cwd=cwd, capture_output=True, text=True, check=True
    ).stdout.strip()


def git(cwd: Path, *args: str) -> str:
    return run(cwd, "git", *args)


def commit_file(repo: Path, name: str, content: str, message: str) -> str:
    (repo / name).write_text(content)
    git(repo, "add", name)
    git(repo, "commit", "-q", "-m", message)
    return git(repo, "rev-parse", "HEAD")


@dataclass
class RepoPair:
    origin: Path   # bare
    clone: Path    # working clone (the "project repo")
    other: Path    # second clone, to simulate a collaborator


@pytest.fixture
def repos(tmp_path: Path) -> RepoPair:
    origin = tmp_path / "origin.git"
    subprocess.run(["git", "init", "-q", "--bare", "-b", "main", str(origin)], check=True)
    clone = tmp_path / "clone"
    other = tmp_path / "other"
    for dest in (clone, other):
        subprocess.run(
            ["git", "clone", "-q", str(origin), str(dest)],
            check=True,
            capture_output=True,
        )
        git(dest, "config", "user.email", "test@example.com")
        git(dest, "config", "user.name", "Test")
    commit_file(clone, "README.md", "hello\n", "initial")
    git(clone, "push", "-q", "origin", "main")
    git(other, "pull", "-q", "origin", "main")
    return RepoPair(origin=origin, clone=clone, other=other)
