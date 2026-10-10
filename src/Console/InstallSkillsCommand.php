<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function Laravel\Prompts\multiselect;

/**
 * Installs the package's AI agent skills, the upgrades to 4.x
 * (`/lc-upgrade-3x-to-4x`, `/lc-upgrade-2x-to-4x` and `/lc-upgrade-1x-to-4x`),
 * into the skills directory of every coding agent used in the project.
 *
 * Skills follow the SKILL.md format that these agents load from their skills
 * directory; no other tooling is required.
 */
class InstallSkillsCommand extends Command
{
    /**
     * Supported agents: display name, files or directories in the project that
     * reveal the agent, and the directory the agent loads skills from.
     *
     * @var array<string, array{name: string, detect: string[], skills: string}>
     */
    public const AGENTS = [
        'claude_code' => ['name' => 'Claude Code', 'detect' => ['.claude', 'CLAUDE.md'], 'skills' => '.claude/skills'],
        'cursor' => ['name' => 'Cursor', 'detect' => ['.cursor'], 'skills' => '.cursor/skills'],
        'copilot' => [
            'name' => 'GitHub Copilot',
            'detect' => ['.github/copilot-instructions.md', '.vscode'],
            'skills' => '.github/skills',
        ],
        'codex' => ['name' => 'Codex', 'detect' => ['.codex'], 'skills' => '.agents/skills'],
        'agents' => ['name' => 'AGENTS.md agents', 'detect' => ['AGENTS.md', '.agents'], 'skills' => '.agents/skills'],
        'junie' => ['name' => 'Junie', 'detect' => ['.junie', '.idea'], 'skills' => '.junie/skills'],
        'opencode' => ['name' => 'OpenCode', 'detect' => ['opencode.json', 'opencode.jsonc'], 'skills' => '.agents/skills'],
        'amp' => ['name' => 'Amp', 'detect' => ['.amp'], 'skills' => '.agents/skills'],
        'gemini' => ['name' => 'Gemini / Antigravity', 'detect' => ['.gemini'], 'skills' => '.agents/skills'],
        'kiro' => ['name' => 'Kiro', 'detect' => ['.kiro'], 'skills' => '.kiro/skills'],
        'pi' => ['name' => 'Pi', 'detect' => ['.pi'], 'skills' => '.pi/skills'],
        'zed' => ['name' => 'Zed', 'detect' => ['.zed'], 'skills' => '.agents/skills'],
        'grok' => ['name' => 'Grok Build', 'detect' => ['.grok'], 'skills' => '.grok/skills'],
        'factory' => ['name' => 'Factory Droid', 'detect' => ['.factory'], 'skills' => '.factory/skills'],
    ];

    /**
     * Skills that earlier releases installed and this one no longer ships: they upgrade to 2.x and 3.x, which the
     * upgrades to 4.x replace. The command removes them from the skills directory of each agent it installs for.
     *
     * @var list<string>
     */
    public const RETIRED_SKILLS = ['plc-upgrade-1x-to-2x', 'lc-upgrade-2x-to-3x'];

    /**
     * @var string
     */
    protected $signature = 'clickhouse:install-skills
                {--agent=* : Agent to install for, detected from the project when omitted: claude_code, cursor, copilot, codex, agents, junie, opencode, amp, gemini, kiro, pi, zed, grok, factory}';

    /**
     * @var string
     */
    protected $description = 'Install the laravel-clickhouse AI agent skills, such as /lc-upgrade-3x-to-4x';

    public function __construct(
        protected Filesystem $files,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $agents = $this->option('agent');

        if ($unknown = array_diff($agents, array_keys(self::AGENTS))) {
            $this->components->error(
                'Unknown agent [' . implode(', ', $unknown) . ']. Use one of: ' . implode(', ', array_keys(self::AGENTS)) . '.'
            );

            return self::FAILURE;
        }

        $agents = $agents ?: $this->detectAgents();

        if ($agents === [] && $this->input->isInteractive()) {
            $agents = multiselect(
                label: 'No coding agent was detected. Which ones do you use?',
                options: array_map(fn (array $agent): string => $agent['name'], self::AGENTS),
                required: true,
            );
        }

        if ($agents === []) {
            $this->components->error(
                'No coding agent was detected. Pass one or more with --agent: ' . implode(', ', array_keys(self::AGENTS)) . '.'
            );

            return self::FAILURE;
        }

        $skills = $this->files->directories($this->skillsSource());
        $installed = [];
        $removed = [];

        foreach ($agents as $agent) {
            $directory = self::AGENTS[$agent]['skills'];

            foreach (self::RETIRED_SKILLS as $retired) {
                $target = $directory . '/' . $retired;

                if (!isset($removed[$target]) && $this->isPackageSkill(base_path($target), $retired)) {
                    $this->files->deleteDirectory(base_path($target));
                    $removed[$target] = true;
                }
            }

            foreach ($skills as $skill) {
                $target = $directory . '/' . basename($skill);

                // Several agents share .agents/skills; write each target once.
                if (!isset($installed[$target])) {
                    $this->files->deleteDirectory(base_path($target));
                    $this->files->copyDirectory($skill, base_path($target));
                    $installed[$target] = [];
                }

                $installed[$target][] = self::AGENTS[$agent]['name'];
            }
        }

        foreach ($installed as $target => $names) {
            $this->components->twoColumnDetail(implode(', ', $names), $target);
        }

        foreach (array_keys($removed) as $target) {
            $this->components->twoColumnDetail('Removed, no longer shipped', $target);
        }

        $this->newLine();
        $this->components->info(
            'Skills installed. Ask your agent to run /lc-upgrade-3x-to-4x to upgrade from 3.x to 4.x,'
            . ' /lc-upgrade-2x-to-4x from 2.x, or /lc-upgrade-1x-to-4x from 1.x.'
        );

        return self::SUCCESS;
    }

    /**
     * Get the agents whose files or directories exist in the project.
     *
     * @return string[]
     */
    protected function detectAgents(): array
    {
        return array_keys(array_filter(
            self::AGENTS,
            fn (array $agent): bool => array_filter(
                $agent['detect'],
                fn (string $path): bool => $this->files->exists(base_path($path))
            ) !== []
        ));
    }

    /**
     * Determine if the directory holds a copy of the given skill: a SKILL.md whose front matter has its name. A
     * directory of that name without such a file is not the package's, and is kept.
     *
     * @param string $directory
     * @param string $name
     * @return bool
     */
    protected function isPackageSkill(string $directory, string $name): bool
    {
        $file = $directory . '/SKILL.md';

        return $this->files->isFile($file)
            && preg_match('/\A---\n.*?^name: ' . preg_quote($name, '/') . '$.*?\n---\n/ms', $this->files->get($file)) === 1;
    }

    /**
     * Get the directory that holds the package's skills, one directory per skill.
     *
     * @return string
     */
    protected function skillsSource(): string
    {
        return dirname(__DIR__, 2) . '/resources/skills';
    }
}
