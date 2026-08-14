# TODO

Classified task list for this repository. Grouped by intent.

## A. Cleanup / repo hygiene

- [x] **Remove legacy Python** — delete `python/` (`src/pablo/*`, `tests/*`, `pyproject.toml`, `poetry.lock`); confirm no references remain.
- [x] **Remove employer / self mentions (repo-wide)**:
  - Personal project configs stay git-ignored/untracked in `app/projects/` (untouched); ship neutral `docs/examples/acme-pim.yaml` and point `README.md` at it.
  - Strip `user` identity in tests: `PollerTest`, `ListingTest`, `GithubTest`, `LinearTest`, `BackupTest` (fixtures + identity), plus the other test files carrying it.
  - Sweep `acme`/`user`/`/home/user/` out of `README.md`, docs and `src` (out of scope: `docs/specification.md` + `docs/castor-migration.md` for A3, `systemd/*.service` paths for B1).
- [x] **Remove spec docs** — delete `docs/specification.md` and `docs/castor-migration.md`; prune cross-references.
- [x] **Add MIT LICENSE** — `LICENSE` at root + reference in `app/composer.json`.

## B. Portability / de-hardcoding

- [x] **Remove hardcoded paths** — `systemd/pablo-dispatch.service:3` `Documentation=file://%h/Sites/user/pablo/README.md`; audit `launchd/*.plist.template` and the `/home/user/...` `startup_script` values (deleted with the acme YAMLs).

## C. Configuration / feature

- [x] **Global PABLO config** — new runtime-resolved `~/.pablo/config.yaml` (resolved in constructors like `Store`/`Agents`, never at container compile time) for cross-project settings: default agent models, default PR description locale, etc., with per-project override.
- [x] **Global agent-model / PR-description-locale per-project override** — `ProjectConfig` readonly fields (`defaultModel`, `prDescriptionLocale`) + `parseProject()` global-config fallback + `toArray()`. No consumer yet; add when the first feature reads them.
- [x] **Move project "defaults" into global config** — retire `projects/default.yaml`; its `DEFAULT_ELIGIBLE` keys move into `~/.pablo/config.yaml`. Rework `Config::merged()` (`app/src/Config/Config.php:101`) and `Config::loadProjects()` (`Config.php:44`) to fall back to global defaults instead of the skipped `default.yaml`.
- [x] **Move projects into `~/.pablo/projects/`** — relocate per-project configs out of the repo into `~/.pablo/projects/`:
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

- [ ] **Dashboard project-type tabs** — `pablo web` (TaskBoard) filter tabs across project types: **All** / **Work** / **Open-source** / **Personal**, driven by each project's `type` in `ProjectConfig`.

> Note: points C2 (defaults) and C3 (projects relocation) both touch `Config` — implement together as one "global config & storage relocation" unit.

## D. Integrations / providers

- [ ] **Test new AgentProvider "OpenChamber"** — verify new agent backend in `app/src/Agents/` alongside Orca/headless.

## E. CI / tooling

- [ ] **Add CI** (inspired by `jolicode/automapper`) — GitHub Actions running the `castor qa:*` gates (test, phpstan, cs-fixer).

## F. Developer experience (DX)

- [ ] **Install script writes a default `~/.pablo/config.yaml`** — `bin/install.sh` should seed a default global config at `~/.pablo/config.yaml` when none exists (idempotent, never overwrite an existing one), so a fresh install starts with a usable `~/.pablo/` layout (config + projects dir) instead of relying on first-run resolution.
- [ ] **Setup wizard (provider CLI detection)** — interactively detect which issue-tracker CLIs are installed (`gh`, `acli`, `linear`) and propose how to install/authenticate the missing ones.
- [x] **Project-creation wizard** — interactively scaffold a new project config in PABLO (name, type, repo path, issue tracker, identity, project key, etc.), writing it to `~/.pablo/projects/`.
