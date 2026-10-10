# Coding-agent skills

The package has skills for coding agents. A skill tells the agent how to do a task step by step.

## Install the skills

```sh
php artisan clickhouse:install-skills
```

The command finds the agents of the project from their files, such as `.claude/`, `.cursor/` or `AGENTS.md`.
It copies each skill into the skills directory of each agent, for example `.claude/skills/lc-upgrade-3x-to-4x/SKILL.md`.

To select the agents, use `--agent`:

```sh
php artisan clickhouse:install-skills --agent=claude_code --agent=cursor
```

| Agent | `--agent` | Skills directory |
| --- | --- | --- |
| Claude Code | `claude_code` | `.claude/skills` |
| Cursor | `cursor` | `.cursor/skills` |
| GitHub Copilot | `copilot` | `.github/skills` |
| Codex | `codex` | `.agents/skills` |
| Agents that read `AGENTS.md` | `agents` | `.agents/skills` |
| Junie | `junie` | `.junie/skills` |
| OpenCode | `opencode` | `.agents/skills` |
| Amp | `amp` | `.agents/skills` |
| Gemini / Antigravity | `gemini` | `.agents/skills` |
| Kiro | `kiro` | `.kiro/skills` |
| Pi | `pi` | `.pi/skills` |
| Zed | `zed` | `.agents/skills` |
| Grok Build | `grok` | `.grok/skills` |
| Factory Droid | `factory` | `.factory/skills` |

The command also removes the skills of earlier releases, `plc-upgrade-1x-to-2x` and `lc-upgrade-2x-to-3x`, when it finds their `SKILL.md`.

## Skills

| Skill | Upgrades |
| --- | --- |
| `/lc-upgrade-3x-to-4x` | `oralunal/laravel-clickhouse` 3.x to 4.x |
| `/lc-upgrade-2x-to-4x` | `oralunal/phpclickhouse-laravel` 2.x to `oralunal/laravel-clickhouse` 4.x |
| `/lc-upgrade-1x-to-4x` | `oralunal/phpclickhouse-laravel` 1.x to `oralunal/laravel-clickhouse` 4.x |

Each skill:

1. records the result of the test suite before the upgrade;
2. updates the dependency;
3. changes the code that the upgrade breaks;
4. asks you before it changes a write or a migration that already ran;
5. checks the result and compares the test suite with the first run;
6. writes a report.

The skills do not run `migrate:fresh`, do not change the data of a database, and do not commit.

If your agent does not show skills as slash commands, ask it to "upgrade laravel-clickhouse to 4.x". The agent finds the skill from its description.

See [Upgrade](/getting-started/upgrading).
