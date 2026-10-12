# Coding-agent skills

The package has skills for coding agents. A skill tells the agent how to do a task step by step.

## Install the skills

```sh
php artisan clickhouse:install-skills
```

The command finds the agents of the project from their files and directories, in the column "Found from" below.
It copies each skill into the skills directory of each agent, for example `.claude/skills/lc-upgrade-3x-to-4x/SKILL.md`.
When it finds no agent, it asks which ones you use. With `--no-interaction`, it fails and names `--agent`.

To select the agents, use `--agent`:

```sh
php artisan clickhouse:install-skills --agent=claude_code --agent=cursor
```

| Agent | `--agent` | Found from | Skills directory |
| --- | --- | --- | --- |
| Claude Code | `claude_code` | `.claude`, `CLAUDE.md` | `.claude/skills` |
| Cursor | `cursor` | `.cursor` | `.cursor/skills` |
| GitHub Copilot | `copilot` | `.github/copilot-instructions.md`, `.vscode` | `.github/skills` |
| Codex | `codex` | `.codex` | `.agents/skills` |
| Other agents that read `.agents/skills` | `agents` | `AGENTS.md`, `.agents` | `.agents/skills` |
| Junie | `junie` | `.junie`, `.idea` | `.junie/skills` |
| OpenCode | `opencode` | `opencode.json`, `opencode.jsonc` | `.agents/skills` |
| Amp | `amp` | `.amp` | `.agents/skills` |
| Gemini / Antigravity | `gemini` | `.gemini` | `.agents/skills` |
| Kiro | `kiro` | `.kiro` | `.kiro/skills` |
| Pi | `pi` | `.pi` | `.pi/skills` |
| Zed | `zed` | `.zed` | `.agents/skills` |
| Grok Build | `grok` | `.grok` | `.grok/skills` |
| Factory Droid | `factory` | `.factory` | `.factory/skills` |

- An IDE directory selects its agent too: `.idea` installs for Junie, and `.vscode` for GitHub Copilot. Use `--agent` to install for fewer agents.
- Agents that share a directory, such as `.agents/skills`, get one copy. Some agents also read the directories of other agents, so a skill can appear more than once.
- The command replaces the skill directories that it installs. Changes that you made to them are lost, and the command warns about them.
- It also removes the skills of earlier releases, `plc-upgrade-1x-to-2x` and `lc-upgrade-2x-to-3x`, from the skills directory of each agent that it installs for in that run.

## Skills

| Skill | Upgrades |
| --- | --- |
| `/lc-upgrade-3x-to-4x` | `oralunal/laravel-clickhouse` 3.x to 4.x |
| `/lc-upgrade-2x-to-4x` | `oralunal/phpclickhouse-laravel` 2.x to `oralunal/laravel-clickhouse` 4.x |
| `/lc-upgrade-1x-to-4x` | `oralunal/phpclickhouse-laravel` 1.x to `oralunal/laravel-clickhouse` 4.x |

Run the test suite and keep its result before you update the dependency. The skills compare the suite after the upgrade with that run.
If 4.x is already installed, a skill asks you for that result, or runs the suite on a copy of the commit before the update.

Each skill:

1. records the result of the test suite before the upgrade;
2. updates the dependency, unless 4.x is already installed;
3. changes the code that the upgrade breaks;
4. asks you before it changes a write or a migration that already ran;
5. checks the result and compares the test suite with the first run;
6. writes a report.

The skills do not run `migrate:fresh`, do not change the data of a database, and do not commit.

## Run a skill

Start a new session of your agent after the install, so that it loads the skills. Then run the skill of your version, such as `/lc-upgrade-3x-to-4x`.
In an agent that does not show skills as slash commands, ask for the skill by name:

| From | Ask |
| --- | --- |
| 3.x | "Upgrade laravel-clickhouse from 3.x to 4.x with the lc-upgrade-3x-to-4x skill." |
| 2.x | "Upgrade phpclickhouse-laravel 2.x to laravel-clickhouse 4.x with the lc-upgrade-2x-to-4x skill." |
| 1.x | "Upgrade phpclickhouse-laravel 1.x to laravel-clickhouse 4.x with the lc-upgrade-1x-to-4x skill." |

A skill that finds the code of another version, such as `PhpClickHouseLaravel\` in the 3.x skill, stops and names the skill to run.

See [Upgrade](/getting-started/upgrading).
