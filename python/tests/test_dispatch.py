import time
from pathlib import Path

import pytest

from pablo import dispatch
from pablo.config import ProjectConfig
from pablo.store import Store


def make_cfg(tmp_path: Path, name: str) -> ProjectConfig:
    return ProjectConfig(
        name=name,
        type="work",
        repo_path=tmp_path / name,
        primary_branch="main",
        worktrees_root=tmp_path / "wt" / name,
        provider="github",
        identity="user",
        project_key="PR",
        sync_strategy="rebase",
        sync_auto_apply=False,
        sync_interval=30,
        poll_interval=10,
        failure_signal=None,
        bot_whitelist=[],
        ci_ignore_checks=[],
    )


@pytest.fixture
def env(tmp_path, monkeypatch):
    monkeypatch.setenv("PABLO_STAMPS_DIR", str(tmp_path / "stamps"))
    monkeypatch.setattr(dispatch, "_preflight_errors", lambda projects: [])
    ran = []
    runners = {
        "sync": lambda cfg, store: ran.append(("sync", cfg.name)),
        "poll": lambda cfg, store: ran.append(("poll", cfg.name)),
    }
    projects = {"a": make_cfg(tmp_path, "a"), "b": make_cfg(tmp_path, "b")}
    store = Store(root=tmp_path / "state")
    return {"projects": projects, "store": store, "runners": runners, "ran": ran}


def test_first_run_runs_everything(env):
    rc = dispatch.run(env["projects"], env["store"], runners=env["runners"])
    assert rc == 0
    assert sorted(env["ran"]) == [
        ("poll", "a"), ("poll", "b"), ("sync", "a"), ("sync", "b"),
    ]


def test_fresh_stamps_skip_jobs(env):
    dispatch.run(env["projects"], env["store"], runners=env["runners"])
    env["ran"].clear()
    rc = dispatch.run(env["projects"], env["store"], runners=env["runners"])
    assert rc == 0
    assert env["ran"] == []


def test_stale_stamp_reruns_job(env, tmp_path):
    dispatch.run(env["projects"], env["store"], runners=env["runners"])
    env["ran"].clear()
    # age project a's poll stamp past its 10-minute interval
    stamp = tmp_path / "stamps" / "a.poll"
    stamp.write_text(str(time.time() - 11 * 60))
    dispatch.run(env["projects"], env["store"], runners=env["runners"])
    assert env["ran"] == [("poll", "a")]


def test_one_project_failure_does_not_block_others(env, capsys):
    def boom(cfg, store):
        raise RuntimeError("provider exploded")

    env["runners"]["sync"] = boom
    rc = dispatch.run(env["projects"], env["store"], runners=env["runners"])
    assert rc == 1
    assert ("poll", "a") in env["ran"] and ("poll", "b") in env["ran"]
    assert "provider exploded" in capsys.readouterr().err


def test_failed_job_does_not_write_stamp(env, tmp_path):
    def boom(cfg, store):
        raise RuntimeError("nope")

    env["runners"]["sync"] = boom
    dispatch.run(env["projects"], env["store"], runners=env["runners"])
    assert not (tmp_path / "stamps" / "a.sync").exists()
    assert (tmp_path / "stamps" / "a.poll").exists()


def test_second_dispatcher_exits_quietly(env, capsys):
    with dispatch._dispatch_lock() as acquired:
        assert acquired
        rc = dispatch.run(env["projects"], env["store"], runners=env["runners"])
    assert rc == 0
    assert env["ran"] == []
    assert "already running" in capsys.readouterr().out


def test_preflight_orca_failure_is_soft(monkeypatch, capsys):
    from pablo import doctor

    results = [
        doctor.CheckResult(cli="gh", installed=True, authenticated=True,
                           detail="ok", hint=""),
        doctor.CheckResult(cli="orca", installed=True, authenticated=False,
                           detail="timed out after 30s", hint="start Orca"),
    ]
    monkeypatch.setattr(doctor, "check_all", lambda projects: results)
    assert dispatch._preflight_errors({}) == []
    assert "headless fallback" in capsys.readouterr().out


def test_preflight_gh_failure_is_hard(monkeypatch):
    from pablo import doctor

    results = [
        doctor.CheckResult(cli="gh", installed=True, authenticated=False,
                           detail="not logged in", hint="run: gh auth login"),
    ]
    monkeypatch.setattr(doctor, "check_all", lambda projects: results)
    errors = dispatch._preflight_errors({})
    assert errors and "gh" in errors[0]


def test_preflight_failure_aborts(env, monkeypatch, capsys):
    monkeypatch.setattr(
        dispatch, "_preflight_errors", lambda projects: ["gh: not authenticated"]
    )
    rc = dispatch.run(env["projects"], env["store"], runners=env["runners"])
    assert rc == 1
    assert env["ran"] == []
    assert "not authenticated" in capsys.readouterr().err
