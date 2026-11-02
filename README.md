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
  <a href="https://korbeil.github.io/pablo/"><b>Website</b></a> ·
  <a href="https://korbeil.github.io/pablo/docs/"><b>Documentation</b></a> ·
  <a href="https://korbeil.github.io/pablo/docs/quickstart/"><b>Quickstart</b></a>
</p>

## Quickstart

```bash
git clone https://github.com/Korbeil/pablo && cd pablo
./bin/install.sh                   # pablo on your PATH, scheduler + dashboard running

cd ~/path/to/your/repository
pablo project:new                  # a short wizard
pablo task:start <issue-url>       # branch + worktree + an agent writing the plan
```

Then watch your tasks on http://127.0.0.1:8321 or with `pablo task:list`.

**You need:** git, PHP 8.4 + Composer, [OpenCode](https://opencode.ai), and [`gh`](https://cli.github.com/) logged in.
Using Jira or Linear, or want the details? Read the [Quickstart](https://korbeil.github.io/pablo/docs/quickstart/) and the [installation guide](https://korbeil.github.io/pablo/docs/installation/).
