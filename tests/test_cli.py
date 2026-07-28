import argparse

from pablo import agents, cli


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


def test_build_parser_parses_internal_launch_agent():
    parser = cli.build_parser()
    args = parser.parse_args(
        ["internal-launch-agent", "--worktree", "/wt", "--agent", "task-analyst", "--prompt", "hi"]
    )
    assert args.func is cli.cmd_internal_launch_agent
    assert args.worktree == "/wt"
    assert args.agent == "task-analyst"
    assert args.prompt == "hi"


def test_build_parser_parses_internal_run_startup_script():
    parser = cli.build_parser()
    args = parser.parse_args(
        ["internal-run-startup-script", "--worktree", "/wt", "--script", "/wt/setup.sh"]
    )
    assert args.func is cli.cmd_internal_run_startup_script
    assert args.worktree == "/wt"
    assert args.script == "/wt/setup.sh"
