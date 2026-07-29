import argparse

from pablo import agents, cli
from pablo import listing


def test_internal_launch_agent_dispatches_to_do_launch_agent(monkeypatch, tmp_path):
    calls = []
    monkeypatch.setattr(
        agents, "_do_launch_agent",
        lambda worktree, agent, prompt: calls.append((worktree, agent, prompt)) or "term_1",
    )
    args = argparse.Namespace(worktree=str(tmp_path), agent="task-analyst", prompt="hello")
    assert cli.cmd_internal_launch_agent(args) == 0
    assert calls == [(tmp_path, "task-analyst", "hello")]


def test_internal_run_startup_script_dispatches_to_do_run_startup_script(monkeypatch, tmp_path):
    calls = []
    script = tmp_path / "setup.sh"
    monkeypatch.setattr(
        agents, "_do_run_startup_script",
        lambda worktree, s: calls.append((worktree, s)) or "term_1",
    )
    args = argparse.Namespace(worktree=str(tmp_path), script=str(script))
    assert cli.cmd_internal_run_startup_script(args) == 0
    assert calls == [(tmp_path, script)]


def test_internal_parser_parses_launch_agent():
    parser = cli._build_internal_parser()
    args = parser.parse_args(
        ["internal-launch-agent", "--worktree", "/wt", "--agent", "task-analyst", "--prompt", "hi"]
    )
    assert args.func is cli.cmd_internal_launch_agent
    assert args.worktree == "/wt"
    assert args.agent == "task-analyst"
    assert args.prompt == "hi"


def test_internal_parser_parses_run_startup_script():
    parser = cli._build_internal_parser()
    args = parser.parse_args(
        ["internal-run-startup-script", "--worktree", "/wt", "--script", "/wt/setup.sh"]
    )
    assert args.func is cli.cmd_internal_run_startup_script
    assert args.worktree == "/wt"
    assert args.script == "/wt/setup.sh"


# --------------------------------------------------------------- slack ---


def test_slack_parser_accepts_no_state():
    args = cli.build_parser().parse_args(["slack"])
    assert args.func is cli.cmd_slack
    assert args.state is None


def test_slack_parser_accepts_single_state():
    args = cli.build_parser().parse_args(["slack", "needs-testing"])
    assert args.state == "needs-testing"


def test_slack_parser_rejects_unknown_state():
    import pytest

    with pytest.raises(SystemExit):
        cli.build_parser().parse_args(["slack", "draft"])


def test_cmd_slack_single_state_prints_one_block(capsys, monkeypatch):
    monkeypatch.setattr(listing, "queue_tasks", lambda *a, **k: [])
    args = argparse.Namespace(state="waiting-review")
    assert cli.cmd_slack(args) == 0
    out = capsys.readouterr().out
    assert out == "No PRs waiting for review right now 🎉\n"


def test_cmd_slack_no_state_prints_both_blocks_with_divider(capsys, monkeypatch):
    calls = []

    def fake_queue(projects, store, state):
        calls.append(state)
        return []

    monkeypatch.setattr(listing, "queue_tasks", fake_queue)
    args = argparse.Namespace(state=None)
    assert cli.cmd_slack(args) == 0
    out = capsys.readouterr().out
    assert calls == ["waiting-review", "needs-testing"]
    assert "No PRs waiting for review right now 🎉" in out
    assert "Nothing needs testing right now 🎉" in out
    assert "―――― review above · QA below ――――" in out
