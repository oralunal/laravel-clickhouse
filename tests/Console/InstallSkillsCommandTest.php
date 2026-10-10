<?php

namespace Tests\Console;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use Oralunal\LaravelClickHouse\ClickhouseServiceProvider;
use Oralunal\LaravelClickHouse\Console\InstallSkillsCommand;

class InstallSkillsCommandTest extends TestCase
{
    private const SKILLS = ['lc-upgrade-1x-to-4x', 'lc-upgrade-2x-to-4x', 'lc-upgrade-3x-to-4x'];

    private string $project;

    private Filesystem $files;

    protected function getPackageProviders($app): array
    {
        return [ClickhouseServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem();
        $this->project = sys_get_temp_dir() . '/phpch-skills-' . bin2hex(random_bytes(4));
        $this->files->ensureDirectoryExists($this->project);
        $this->app->setBasePath($this->project);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->project);

        parent::tearDown();
    }

    public function testInstallsIntoEveryDetectedAgent(): void
    {
        $this->files->ensureDirectoryExists($this->project . '/.claude');
        $this->files->ensureDirectoryExists($this->project . '/.cursor');

        $this->artisan('clickhouse:install-skills')
            ->expectsOutputToContain('.claude/skills/lc-upgrade-1x-to-4x')
            ->expectsOutputToContain('.claude/skills/lc-upgrade-2x-to-4x')
            ->expectsOutputToContain('.claude/skills/lc-upgrade-3x-to-4x')
            ->expectsOutputToContain('.cursor/skills/lc-upgrade-1x-to-4x')
            ->expectsOutputToContain('.cursor/skills/lc-upgrade-2x-to-4x')
            ->expectsOutputToContain('.cursor/skills/lc-upgrade-3x-to-4x')
            ->expectsOutputToContain('/lc-upgrade-3x-to-4x to upgrade from 3.x to 4.x')
            ->assertSuccessful();

        $this->assertInstalled('.claude/skills');
        $this->assertInstalled('.cursor/skills');
        $this->assertDirectoryDoesNotExist($this->project . '/.github');
    }

    public function testAgentsSharingADirectoryGetOneCopy(): void
    {
        $this->artisan('clickhouse:install-skills', ['--agent' => ['codex', 'opencode']])
            ->expectsOutputToContain('Codex, OpenCode')
            ->assertSuccessful();

        $this->assertInstalled('.agents/skills');
        $this->assertDirectoryDoesNotExist($this->project . '/.claude');
    }

    public function testReinstallReplacesTheSkillDirectory(): void
    {
        $stale = $this->project . '/.claude/skills/lc-upgrade-3x-to-4x/stale.md';
        $this->files->ensureDirectoryExists(dirname($stale));
        $this->files->put($stale, 'old');

        $this->artisan('clickhouse:install-skills', ['--agent' => ['claude_code']])->assertSuccessful();

        $this->assertFileDoesNotExist($stale);
        $this->assertInstalled('.claude/skills');
    }

    public function testRemovesTheSkillsThatEarlierReleasesInstalled(): void
    {
        foreach (InstallSkillsCommand::RETIRED_SKILLS as $retired) {
            $this->files->ensureDirectoryExists($this->project . "/.claude/skills/{$retired}");
            $this->files->put($this->project . "/.claude/skills/{$retired}/SKILL.md", "---\nname: {$retired}\ndescription: Old.\n---\n\n# Old\n");
        }

        $this->artisan('clickhouse:install-skills', ['--agent' => ['claude_code']])
            ->expectsOutputToContain('.claude/skills/plc-upgrade-1x-to-2x')
            ->expectsOutputToContain('.claude/skills/lc-upgrade-2x-to-3x')
            ->assertSuccessful();

        foreach (InstallSkillsCommand::RETIRED_SKILLS as $retired) {
            $this->assertDirectoryDoesNotExist($this->project . "/.claude/skills/{$retired}");
        }
        $this->assertInstalled('.claude/skills');
    }

    public function testKeepsADirectoryOfARetiredNameThatIsNotThePackagesSkill(): void
    {
        $own = $this->project . '/.claude/skills/lc-upgrade-2x-to-3x';
        $this->files->ensureDirectoryExists($own);
        $this->files->put($own . '/SKILL.md', "---\nname: my-own-skill\ndescription: Mine.\n---\n");
        $this->files->ensureDirectoryExists($this->project . '/.cursor/skills/plc-upgrade-1x-to-2x');
        $this->files->put($this->project . '/.cursor/skills/plc-upgrade-1x-to-2x/notes.md', 'mine');

        $this->artisan('clickhouse:install-skills', ['--agent' => ['claude_code', 'cursor']])->assertSuccessful();

        $this->assertFileExists($own . '/SKILL.md');
        $this->assertFileExists($this->project . '/.cursor/skills/plc-upgrade-1x-to-2x/notes.md');
    }

    public function testTheRetiredSkillsAreNoLongerShipped(): void
    {
        foreach (InstallSkillsCommand::RETIRED_SKILLS as $retired) {
            $this->assertDirectoryDoesNotExist(dirname(__DIR__, 2) . "/resources/skills/{$retired}");
        }
    }

    /**
     * The upgrades from 1.x and 2.x hand the 4.0 changes to the upgrade from 3.x, which the package ships next to
     * them and in vendor/.
     */
    public function testTheUpgradesFromOneAndTwoPointAtTheUpgradeFromThree(): void
    {
        $skills = dirname(__DIR__, 2) . '/resources/skills';
        $this->assertFileExists($skills . '/lc-upgrade-3x-to-4x/SKILL.md');

        foreach (['lc-upgrade-1x-to-4x', 'lc-upgrade-2x-to-4x'] as $skill) {
            $contents = $this->files->get("{$skills}/{$skill}/SKILL.md");

            $this->assertStringContainsString('vendor/oralunal/laravel-clickhouse/resources/skills/lc-upgrade-3x-to-4x/SKILL.md', $contents, $skill);
            $this->assertStringContainsString('composer require oralunal/laravel-clickhouse:^4.0', $contents, $skill);
        }
    }

    public function testFailsWithoutAnyAgentWhenNotInteractive(): void
    {
        $this->artisan('clickhouse:install-skills', ['--no-interaction' => true])
            ->expectsOutputToContain('--agent')
            ->assertFailed();

        $this->assertSame([], $this->files->allFiles($this->project, true));
    }

    public function testRejectsUnknownAgents(): void
    {
        $this->artisan('clickhouse:install-skills', ['--agent' => ['notepad']])
            ->expectsOutputToContain('Unknown agent [notepad]')
            ->assertFailed();
    }

    public function testEverySkillHasValidFrontmatter(): void
    {
        $skills = $this->files->directories(dirname(__DIR__, 2) . '/resources/skills');
        $this->assertNotEmpty($skills);

        foreach ($skills as $skill) {
            $contents = $this->files->get($skill . '/SKILL.md');
            $this->assertSame(1, preg_match('/\A---\n(.*?)\n---\n/s', $contents, $frontmatter), basename($skill));
            $this->assertSame(1, preg_match('/^name: (\S+)$/m', $frontmatter[1], $name));
            $this->assertSame(1, preg_match('/^description: (.+)$/m', $frontmatter[1], $description));

            // Agent Skills: the name matches the directory and uses lowercase letters, digits and hyphens.
            $this->assertSame(basename($skill), $name[1]);
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name[1]);
            $this->assertLessThanOrEqual(1024, mb_strlen($description[1]));
        }
    }

    public function testEveryAgentHasADetectionRuleAndSkillsDirectory(): void
    {
        $help = $this->app->make(InstallSkillsCommand::class)->getDefinition()->getOption('agent')->getDescription();

        foreach (InstallSkillsCommand::AGENTS as $key => $agent) {
            $this->assertNotSame([], $agent['detect'], $key);
            $this->assertStringEndsWith('/skills', $agent['skills'], $key);
            $this->assertStringContainsString($key, $help, "--agent help does not list {$key}");
        }
    }

    private function assertInstalled(string $directory): void
    {
        foreach (self::SKILLS as $skill) {
            $this->assertFileEquals(
                dirname(__DIR__, 2) . "/resources/skills/{$skill}/SKILL.md",
                $this->project . "/{$directory}/{$skill}/SKILL.md"
            );
        }
    }
}
