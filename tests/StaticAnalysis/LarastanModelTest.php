<?php

declare(strict_types=1);

namespace Tests\StaticAnalysis;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs PHPStan with Larastan on the models in tests/StaticAnalysis/Fixtures, as
 * an app's analysis would see them.
 *
 * Larastan's stubs of some Eloquent traits declare
 * "@phpstan-require-extends Illuminate\Database\Eloquent\Model". When BaseModel
 * used such a trait, PHPStan reported class.missingExtends on every class that
 * extends BaseModel. The fixtures must stay free of errors. AttributeTypes
 * checks the types PHPStan infers for the attribute API of such a model.
 *
 * Larastan boots Laravel through orchestra/testbench and testbench.yaml; no
 * ClickHouse server is needed.
 */
class LarastanModelTest extends TestCase
{
    private const CONTROL_FIXTURE = 'ControlUsesEloquentHasAttributes.php';

    /**
     * The messages PHPStan reported, by file name.
     *
     * @var array<string, list<array{message: string, line: int, identifier: string}>>|null
     */
    private static ?array $messagesByFile = null;

    public function testLarastanStubsAreLoaded(): void
    {
        $identifiers = array_column(self::analyse()[self::CONTROL_FIXTURE] ?? [], 'identifier');

        $this->assertContains(
            'class.missingExtends',
            $identifiers,
            'The control fixture uses Eloquent\'s HasAttributes without extending Model, so Larastan must report class.missingExtends on it. '
            . 'Without that error the analysis did not load Larastan, and the other assertions prove nothing.'
        );
    }

    public function testModelsThatExtendBaseModelHaveNoErrors(): void
    {
        $messages = self::analyse();
        unset($messages[self::CONTROL_FIXTURE]);

        $this->assertSame([], $messages);
    }

    /**
     * Run PHPStan once for the class, with a result cache of its own.
     *
     * @return array<string, list<array{message: string, line: int, identifier: string}>>
     */
    private static function analyse(): array
    {
        if (self::$messagesByFile !== null) {
            return self::$messagesByFile;
        }

        $files = new Filesystem();
        $tmpDir = sys_get_temp_dir() . '/laravel-clickhouse-phpstan-' . bin2hex(random_bytes(6));
        $files->ensureDirectoryExists($tmpDir);

        // A configuration of this run only: the fixtures' configuration, with a result
        // cache that no other run reads.
        $files->put($tmpDir . '/phpstan.neon', implode("\n", [
            'includes:',
            '    - ' . json_encode(__DIR__ . '/phpstan.neon', JSON_UNESCAPED_SLASHES),
            'parameters:',
            '    tmpDir: ' . json_encode($tmpDir . '/cache', JSON_UNESCAPED_SLASHES),
            '',
        ]));

        $process = new Process(
            [PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '--configuration=' . $tmpDir . '/phpstan.neon', '--error-format=json', '--no-progress', '--memory-limit=1G'],
            dirname(__DIR__, 2),
            null,
            null,
            120,
        );

        try {
            $process->run();
        } finally {
            $files->deleteDirectory($tmpDir);
        }

        $result = json_decode($process->getOutput(), true);

        if (! is_array($result) || ! isset($result['files']) || ($result['errors'] ?? []) !== []) {
            self::fail("PHPStan did not finish the analysis (exit code {$process->getExitCode()}):\n" . $process->getOutput() . $process->getErrorOutput());
        }

        $messagesByFile = [];

        foreach ($result['files'] as $path => $file) {
            foreach ($file['messages'] as $message) {
                $messagesByFile[basename($path)][] = [
                    'message' => $message['message'],
                    'line' => $message['line'],
                    'identifier' => $message['identifier'] ?? '',
                ];
            }
        }

        return self::$messagesByFile = $messagesByFile;
    }
}
