<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="site/assets/logo-light.svg">
    <img src="site/assets/logo.svg" alt="PABLO" width="300">
  </picture>
</p>

<p align="center"><i>Personal Assistant for Boring Logic &amp; Operations</i></p>

<p align="center">
  PABLO watches your pull requests and launches the right AI agent, in the right worktree, when something needs doing.
</p>

<p align="center">
  <a href="https://getpablo.dev/"><b>Website</b></a> ·
  <a href="https://getpablo.dev/docs/"><b>Documentation</b></a> ·
  <a href="https://getpablo.dev/docs/quickstart/"><b>Quickstart</b></a>
</p>

## Quickstart

```bash
curl -fsSL https://getpablo.dev/installer | bash   # interactive: pablo on your PATH, scheduler + dashboard running

cd ~/path/to/your/repository
pablo project:new                  # a short wizard
pablo task:start <issue-url>       # branch + worktree + an agent writing the plan
```

Then watch your tasks on http://127.0.0.1:8321 or with `pablo task:list`.

**You need:** git, [OpenCode](https://opencode.ai) (or Claude Code, or Copilot CLI), and [`gh`](https://cli.github.com/) logged in. PABLO itself is a single binary: no PHP needed.
Using Jira or Linear, or want the details? Read the [Quickstart](https://getpablo.dev/docs/quickstart/) and the [installation guide](https://getpablo.dev/docs/installation/).
