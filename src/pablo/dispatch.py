"""The cron dispatcher, fired by the ``pablo-dispatch`` systemd user timer.

One timer, every 5 minutes; per-project cadence lives in the configs
(``sync.interval_minutes`` / ``state_polling.interval_minutes``) and is
enforced here via last-run stamps, so adding or removing a project needs no
scheduler change at all. A global flock prevents overlapping dispatcher
runs; a failed CLI preflight aborts the whole run fast and loud.
"""

from __future__ import annotations

import fcntl
import os
import sys
import time
import traceback
from contextlib import contextmanager
from pathlib import Path
from typing import Callable, Iterator

from pablo.config import ProjectConfig
from pablo.store import Store


def stamps_dir() -> Path:
    override = os.environ.get("PABLO_STAMPS_DIR")
    if override:
        return Path(override)
    return Path("~/.pablo/stamps").expanduser()


def _preflight_errors(projects: dict[str, ProjectConfig]) -> list[str]:
    from pablo import doctor

    return [
        f"{result.cli}: {result.detail} ({result.hint})"
        for result in doctor.check_all(projects)
        if not result.ok
    ]


@contextmanager
def _dispatch_lock() -> Iterator[bool]:
    path = stamps_dir() / "dispatch.lock"
    path.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(path, os.O_RDWR | os.O_CREAT, 0o644)
    try:
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            yield False
            return
        yield True
    finally:
        os.close(fd)


def _run_sync(cfg: ProjectConfig, store: Store) -> None:
    from pablo import sync

    reports = sync.sync_project(cfg, store, apply=None)
    print(f"[{cfg.name}] sync:\n{sync.render_reports(reports)}")


def _run_poll(cfg: ProjectConfig, store: Store) -> None:
    from pablo import poller

    for event in poller.poll_project(cfg, store):
        print(f"[{cfg.name}] {event}")


DEFAULT_RUNNERS: dict[str, Callable[[ProjectConfig, Store], None]] = {
    "sync": _run_sync,
    "poll": _run_poll,
}

JOB_INTERVALS: dict[str, Callable[[ProjectConfig], int]] = {
    "sync": lambda cfg: cfg.sync_interval,
    "poll": lambda cfg: cfg.poll_interval,
}


def _stamp_path(cfg: ProjectConfig, job: str) -> Path:
    return stamps_dir() / f"{cfg.name}.{job}"


def _is_due(cfg: ProjectConfig, job: str, now: float) -> bool:
    stamp = _stamp_path(cfg, job)
    if not stamp.exists():
        return True
    try:
        last = float(stamp.read_text().strip())
    except ValueError:
        return True
    return now - last >= JOB_INTERVALS[job](cfg) * 60


def run(
    projects: dict[str, ProjectConfig],
    store: Store,
    *,
    runners: dict[str, Callable[[ProjectConfig, Store], None]] | None = None,
    now: float | None = None,
) -> int:
    runners = runners if runners is not None else DEFAULT_RUNNERS
    now = now if now is not None else time.time()

    with _dispatch_lock() as acquired:
        if not acquired:
            print("pablo dispatch: already running, nothing to do")
            return 0

        errors = _preflight_errors(projects)
        if errors:
            print(
                "pablo dispatch: CLI preflight failed, aborting:\n"
                + "\n".join(f"  ❌ {error}" for error in errors),
                file=sys.stderr,
            )
            return 1

        failed = False
        for cfg in projects.values():
            for job, runner in runners.items():
                if not _is_due(cfg, job, now):
                    continue
                try:
                    runner(cfg, store)
                except Exception:
                    failed = True
                    print(
                        f"pablo dispatch: {job} failed for project {cfg.name}:\n"
                        + traceback.format_exc(),
                        file=sys.stderr,
                    )
                    continue  # stamp not written: retried next tick
                stamp = _stamp_path(cfg, job)
                stamp.parent.mkdir(parents=True, exist_ok=True)
                stamp.write_text(str(now))
        return 1 if failed else 0
