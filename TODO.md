# TODO

Classified task list for this repository. Grouped by intent.

## A. Cleanup / repo hygiene

- [ ] **Remove legacy Python** — delete `python/` (`src/pablo/*`, `tests/*`, `pyproject.toml`, `poetry.lock`); confirm no references remain.
- [ ] **Remove employer / self mentions (repo-wide)**:
  - Delete `app/projects/acme-{oms,retail,cms,pim}.yaml`; ship a neutral `acme-*.yaml` example.
  - Strip `user` identity in tests: `PollerTest.php:129,171,581,613`, `ListingTest.php:109`, `Tracker/GithubTest.php:68`, `Tracker/LinearTest.php:68`, `BackupTest.php` (`acme` fixtures + `identity: user`).
  - Sweep `acme`/`user`/`/home/user/` out of `README.md` + docs.
- [ ] **Remove spec docs** — delete `docs/specification.md` and `docs/castor-migration.md`; prune cross-references.
- [ ] **Add MIT LICENSE** — `LICENSE` at root + reference in `app/composer.json`.

## B. Portability / de-hardcoding

- [ ] **Remove hardcoded paths** — `systemd/pablo-dispatch.service:3` `Documentation=file://%h/Sites/user/pablo/README.md`; audit `launchd/*.plist.template` and the `/home/user/...` `startup_script` values (deleted with the acme YAMLs).

## C. Configuration / feature

- [ ] **Global PABLO config** — new runtime-resolved `~/.pablo/config.yaml` (resolved in constructors like `Store`/`Agents`, never at container compile time) for cross-project settings: default agent models, default PR description locale, etc., with per-project override.
- [ ] **Move project "defaults" into global config** — retire `projects/default.yaml`; its `DEFAULT_ELIGIBLE` keys move into `~/.pablo/config.yaml`. Rework `Config::merged()` (`app/src/Config/Config.php:101`) and `Config::loadProjects()` (`Config.php:44`) to fall back to global defaults instead of the skipped `default.yaml`.
- [ ] **Move projects into `~/.pablo/projects/`** — relocate per-project configs out of the repo into `~/.pablo/projects/`:
  - Change `Config::projectsDir()` (`Config.php:21`) default from repo `app/projects` to `~/.pablo/projects`; keep the `PABLO_PROJECTS_DIR` override (tests rely on it).
  - Ship `acme-*.yaml` *example* templates in the repo, but real projects live per-user under `~/.pablo/projects/`.
  - Update `Backup`/`Restore` (`BackupCommand`, `RestoreCommand`, `tests/Backup/BackupTest.php`) to read from/write `~/.pablo/projects/`.
  - Bonus: since the acme configs leave the repo entirely, this fully resolves the "remove employer/self" repo-side issue in A.
- [ ] **Integration selection** — global config block:
  ```yaml
  integrations:
    issue_tracker: [github, jira]   # from ProviderRegistry::PROVIDER_NAMES
    git_forge: github               # github | gitea | ...
  ```
  - **Agents**: template `opencode/agents/*.md` from the global config so only enabled providers are mentioned (task-analyst "Retrieving the ticket", task-feedback, etc.).
  - **Poller**: only projects whose `issue_tracker.provider` is enabled get tracker-polled (skip+warn), gating `ProviderRegistry::get()` in `Poller.php:99` and `StateMachine.php:153`.

> Note: points C2 (defaults) and C3 (projects relocation) both touch `Config` — implement together as one "global config & storage relocation" unit.

## D. Integrations / providers

- [ ] **Test new AgentProvider "OpenChamber"** — verify new agent backend in `app/src/Agents/` alongside Orca/headless.

## E. CI / tooling

- [ ] **Add CI** (inspired by `jolicode/automapper`) — GitHub Actions running the `castor qa:*` gates (test, phpstan, cs-fixer).

## F. Developer experience (DX)

- [ ] **Setup wizard (provider CLI detection)** — interactively detect which issue-tracker CLIs are installed (`gh`, `acli`, `linear`) and propose how to install/authenticate the missing ones.
- [ ] **Project-creation wizard** — interactively scaffold a new project config in PABLO (name, type, repo path, issue tracker, identity, project key, etc.), writing it to `~/.pablo/projects/`.
