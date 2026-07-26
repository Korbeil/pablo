from pathlib import Path

import pytest

from pablo import PabloError
from pablo.config import load_projects

DEFAULTS = """\
sync:
  strategy: rebase
  auto_apply: false
  interval_minutes: 30
state_polling:
  interval_minutes: 10
review:
  bot_whitelist: []
ci:
  ignore_checks: []
"""

FULL_PROJECT = """\
name: wallet-kit
type: open-source
repo:
  path: ~/dev/wallet-kit
  primary_branch: main
worktrees_root: ~/dev/wallet-kit-worktrees
issue_tracker:
  provider: github
  identity: bfontaine
  project_key: WK
sync:
  strategy: merge
  auto_apply: true
  interval_minutes: 5
state_polling:
  interval_minutes: 2
testing:
  failure_signal: qa-failed
review:
  bot_whitelist: ["copilot-pull-request-reviewer[bot]"]
ci:
  ignore_checks: ["approval"]
"""

MINIMAL_PROJECT = """\
name: mini
type: personal
repo:
  path: ~/dev/mini
  primary_branch: master
issue_tracker:
  provider: github
  identity: user
  project_key: MI
"""


@pytest.fixture
def projects_dir(tmp_path: Path) -> Path:
    d = tmp_path / "projects"
    d.mkdir()
    (d / "default.yaml").write_text(DEFAULTS)
    return d


def test_skips_default_yaml(projects_dir: Path):
    (projects_dir / "mini.yaml").write_text(MINIMAL_PROJECT)
    projects = load_projects(projects_dir)
    assert set(projects) == {"mini"}


def test_per_key_merge(projects_dir: Path):
    (projects_dir / "mini.yaml").write_text(
        MINIMAL_PROJECT + "sync:\n  interval_minutes: 5\n"
    )
    cfg = load_projects(projects_dir)["mini"]
    assert cfg.sync_interval == 5          # overridden
    assert cfg.sync_strategy == "rebase"   # from defaults
    assert cfg.sync_auto_apply is False    # from defaults
    assert cfg.poll_interval == 10         # from defaults
    assert cfg.bot_whitelist == []         # from defaults
    assert cfg.ci_ignore_checks == []      # from defaults


def test_project_value_wins(projects_dir: Path):
    (projects_dir / "wallet-kit.yaml").write_text(FULL_PROJECT)
    cfg = load_projects(projects_dir)["wallet-kit"]
    assert cfg.sync_strategy == "merge"
    assert cfg.sync_auto_apply is True
    assert cfg.sync_interval == 5
    assert cfg.poll_interval == 2
    assert cfg.bot_whitelist == ["copilot-pull-request-reviewer[bot]"]
    assert cfg.ci_ignore_checks == ["approval"]
    assert cfg.failure_signal == "qa-failed"
    assert cfg.worktrees_root == Path("~/dev/wallet-kit-worktrees").expanduser()
    assert cfg.repo_path == Path("~/dev/wallet-kit").expanduser()


def test_worktrees_root_default(projects_dir: Path):
    (projects_dir / "mini.yaml").write_text(MINIMAL_PROJECT)
    cfg = load_projects(projects_dir)["mini"]
    assert cfg.worktrees_root == Path("~/.pablo/worktrees/mini").expanduser()


def test_site_optional(projects_dir: Path):
    (projects_dir / "mini.yaml").write_text(MINIMAL_PROJECT)
    assert load_projects(projects_dir)["mini"].site is None
    (projects_dir / "mini.yaml").write_text(
        MINIMAL_PROJECT.replace(
            "  project_key: MI\n", "  project_key: MI\n  site: acme.atlassian.net\n"
        )
    )
    assert load_projects(projects_dir)["mini"].site == "acme.atlassian.net"


def test_missing_required_key_names_file_and_key(projects_dir: Path):
    (projects_dir / "broken.yaml").write_text("name: broken\ntype: work\n")
    with pytest.raises(PabloError) as exc:
        load_projects(projects_dir)
    assert "broken.yaml" in str(exc.value)
    assert "repo.path" in str(exc.value)


def test_unknown_type_rejected(projects_dir: Path):
    (projects_dir / "bad.yaml").write_text(
        MINIMAL_PROJECT.replace("type: personal", "type: hobby")
    )
    with pytest.raises(PabloError) as exc:
        load_projects(projects_dir)
    assert "hobby" in str(exc.value)


def test_unknown_provider_rejected(projects_dir: Path):
    (projects_dir / "bad.yaml").write_text(
        MINIMAL_PROJECT.replace("provider: github", "provider: gitlab")
    )
    with pytest.raises(PabloError) as exc:
        load_projects(projects_dir)
    assert "gitlab" in str(exc.value)


def test_github_requires_project_key(projects_dir: Path):
    (projects_dir / "bad.yaml").write_text(
        MINIMAL_PROJECT.replace("  project_key: MI\n", "")
    )
    with pytest.raises(PabloError) as exc:
        load_projects(projects_dir)
    assert "project_key" in str(exc.value)
