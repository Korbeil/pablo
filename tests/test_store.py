import json
import subprocess
import sys
import time
from pathlib import Path

import pytest

from pablo import PabloError
from pablo.model import IN_PROGRESS, Issue, Task
from pablo.store import Store, task_lock


@pytest.fixture
def store(tmp_path: Path) -> Store:
    return Store(root=tmp_path / "state")


def make_task(project: str = "wallet-kit", branch: str = "wk-45") -> Task:
    return Task(
        project=project,
        branch=branch,
        worktree_path=Path(f"/tmp/worktrees/{project}/{branch}"),
        state=IN_PROGRESS,
        issue=Issue(
            provider="github",
            key="45",
            url="https://github.com/o/r/issues/45",
            title="Fix callback verification",
            project_key="WK",
        ),
    )


def test_save_and_get_roundtrip(store: Store):
    task = make_task()
    store.save(task)
    loaded = store.get("wallet-kit", "wk-45")
    assert loaded is not None
    assert loaded.branch == "wk-45"
    assert loaded.state == IN_PROGRESS
    assert loaded.issue is not None
    assert loaded.issue.title == "Fix callback verification"
    assert loaded.task_analyst_ran is False
    assert loaded.merged is False


def test_get_missing_returns_none(store: Store):
    assert store.get("wallet-kit", "nope") is None


def test_delete_removes_record(store: Store):
    store.save(make_task())
    store.delete("wallet-kit", "wk-45")
    assert store.get("wallet-kit", "wk-45") is None


def test_all_tasks_across_projects(store: Store):
    store.save(make_task("a", "a-1"))
    store.save(make_task("b", "b-1"))
    assert {t.project for t in store.all_tasks()} == {"a", "b"}
    assert [t.project for t in store.all_tasks("a")] == ["a"]


def test_prompt_task_serializes_null_issue(store: Store):
    task = make_task()
    task.issue = None
    task.summary = "fix callback verification"
    store.save(task)
    raw = json.loads((store.root / "wallet-kit" / "wk-45.json").read_text())
    assert raw["issue"] is None
    assert raw["summary"] == "fix callback verification"


def test_lock_released_on_context_exit(store: Store):
    with task_lock(store, "p", "b"):
        pass
    with task_lock(store, "p", "b"):  # re-acquirable → was released
        pass


HOLDER_SCRIPT = """
import sys, time
sys.path.insert(0, {src!r})
from pathlib import Path
from pablo.store import Store, task_lock
with task_lock(Store(root=Path({root!r})), "p", "b"):
    print("held", flush=True)
    time.sleep(5)
"""


def test_lock_excludes_second_holder(store: Store, tmp_path: Path):
    src = str(Path(__file__).resolve().parents[1] / "src")
    script = HOLDER_SCRIPT.format(src=src, root=str(store.root))
    proc = subprocess.Popen(
        [sys.executable, "-c", script], stdout=subprocess.PIPE, text=True
    )
    try:
        assert proc.stdout.readline().strip() == "held"
        start = time.monotonic()
        with pytest.raises(PabloError, match="locked"):
            with task_lock(store, "p", "b", timeout_s=1):
                pass
        assert time.monotonic() - start < 4
    finally:
        proc.kill()
        proc.wait()


def test_task_for_cwd_rejects_non_task_dir(store: Store, tmp_path: Path):
    with pytest.raises(PabloError, match="not .* PABLO task worktree"):
        store.task_for_cwd(tmp_path)


def test_task_for_cwd_resolves_worktree(store: Store, tmp_path: Path):
    wt = tmp_path / "wt"
    wt.mkdir()
    subprocess.run(["git", "init", "-q", str(wt)], check=True)
    task = make_task()
    task.worktree_path = wt
    store.save(task)
    (wt / "sub").mkdir()
    found = store.task_for_cwd(wt / "sub")
    assert found.branch == "wk-45"
