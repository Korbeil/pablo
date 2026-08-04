"""Central task-state store, owned by PABLO's installation.

Lives under ``~/.pablo/state/<project>/<branch>.json`` (never inside a
project repo). Each task has a sibling ``.lock`` file used with flock(2) so
the sync job, the state poller, and interactive commands never interleave on
the same task; the kernel releases the lock if the holder crashes.
"""

from __future__ import annotations

import fcntl
import json
import os
import subprocess
import sys
import time
from contextlib import contextmanager
from pathlib import Path
from typing import Iterator

from pablo import PabloError
from pablo.model import Task, utcnow

LOCK_TIMEOUT_S = 30


def default_root() -> Path:
    override = os.environ.get("PABLO_STATE_DIR")
    if override:
        return Path(override)
    return Path("~/.pablo/state").expanduser()


class Store:
    def __init__(self, root: Path | None = None):
        self.root = root if root is not None else default_root()

    def _path(self, project: str, branch: str) -> Path:
        return self.root / project / f"{branch}.json"

    def lock_path(self, project: str, branch: str) -> Path:
        return self.root / project / f"{branch}.lock"

    def get(self, project: str, branch: str) -> Task | None:
        path = self._path(project, branch)
        if not path.exists():
            return None
        return Task.from_json(json.loads(path.read_text()))

    def save(self, task: Task) -> None:
        task.updated_at = utcnow()
        path = self._path(task.project, task.branch)
        path.parent.mkdir(parents=True, exist_ok=True)
        tmp = path.with_suffix(".json.tmp")
        tmp.write_text(json.dumps(task.to_json(), indent=2) + "\n")
        os.replace(tmp, path)

    def delete(self, project: str, branch: str) -> None:
        self._path(project, branch).unlink(missing_ok=True)
        self.lock_path(project, branch).unlink(missing_ok=True)

    def all_tasks(self, project: str | None = None) -> list[Task]:
        if not self.root.is_dir():
            return []
        tasks = []
        for path in sorted(self.root.glob("*/*.json")):
            if project is not None and path.parent.name != project:
                continue
            tasks.append(Task.from_json(json.loads(path.read_text())))
        return tasks

    def task_for_cwd(self, cwd: Path) -> Task:
        """Resolve the task owning ``cwd`` (a PABLO-created worktree)."""
        try:
            top = subprocess.run(
                ["git", "-C", str(cwd), "rev-parse", "--show-toplevel"],
                capture_output=True,
                text=True,
                check=True,
            ).stdout.strip()
        except subprocess.CalledProcessError:
            raise PabloError(f"{cwd} is not a git worktree, so not a PABLO task worktree")
        top_path = Path(top).resolve()
        for task in self.all_tasks():
            if Path(task.worktree_path).resolve() == top_path:
                return task
        raise PabloError(
            f"{top_path} is not a PABLO task worktree (no task record matches it)"
        )


@contextmanager
def task_lock(
    store: Store, project: str, branch: str, timeout_s: float = LOCK_TIMEOUT_S
) -> Iterator[None]:
    """Exclusive per-task lock. Raises PabloError if held past timeout."""
    path = store.lock_path(project, branch)
    path.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(path, os.O_RDWR | os.O_CREAT, 0o644)
    deadline = time.monotonic() + timeout_s
    try:
        while True:
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    holder = ""
                    try:
                        holder = path.read_text().strip()
                    except OSError:
                        pass
                    raise PabloError(
                        f"task {project}/{branch} is locked"
                        + (f" by {holder}" if holder else "")
                    )
                time.sleep(0.2)
        os.ftruncate(fd, 0)
        os.write(
            fd,
            json.dumps(
                {"pid": os.getpid(), "argv": sys.argv, "acquired_at": utcnow()}
            ).encode(),
        )
        yield
    finally:
        os.close(fd)  # closing the fd releases the flock
