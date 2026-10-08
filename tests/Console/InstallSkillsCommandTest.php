<?php

namespace Tests\Console;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use PhpClickHouseLaravel\ClickhouseServiceProvider;
use PhpClickHouseLaravel\Console\InstallSkillsCommand;

class InstallSkillsCommandTest extends TestCase
{
    private const SKILL = 'plc-upgrade-1x-to-2x';

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
            ->expectsOutputToContain('.claude/skills/' . self::SKILL)
            ->expectsOutputToContain('.cursor/skills/' . self::SKILL)
            ->expectsOutputToContain('/plc-upgrade-1x-to-2x')
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
        $stale = $this->project . '/.claude/skills/' . self::SKILL . '/stale.md';
        $this->files->ensureDirectoryExists(dirname($stale));
        $this->files->put($stale, 'old');

        $this->artisan('clickhouse:install-skills', ['--agent' => ['claude_code']])->assertSuccessful();

        $this->assertFileDoesNotExist($stale);
        $this->assertInstalled('.claude/skills');
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
        $this->assertFileEquals(
            dirname(__DIR__, 2) . '/resources/skills/' . self::SKILL . '/SKILL.md',
            $this->project . "/{$directory}/" . self::SKILL . '/SKILL.md'
        );
    }
}
