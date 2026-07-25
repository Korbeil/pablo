import subprocess
from dataclasses import dataclass
from pathlib import Path

import pytest


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
