<?php

declare(strict_types=1);

namespace Tests\Types;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * PHPStan, with Larastan and the extension of the package (extension.neon), reads the types that the package
 * returns. The assertions are the assertType() calls of the files in data/:
 * - row-types.php: each row of QueryBuilder is a stdClass object, and the base query of a ClickHouse model is a
 *   QueryBuilder. RowObjectsTest checks the rows that the same methods return;
 * - extension.php: the columns of the models come from the ClickHouse schema dump in schema/, with the PHP types of
 *   the values that ResultValues returns, and the facades give the package's types for ClickHouse connections.
 *
 * PHPStan runs in its own process, because Larastan boots a Laravel application, whose error and exception handlers
 * must not stay in the process of the other tests.
 */
class RowTypesTest extends TestCase
{
    public function testPhpStanReadsTheRowsAsArrays(): void
    {
        $process = new Process(
            [
                PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '--configuration=tests/Types/phpstan.neon',
                '--error-format=json', '--no-progress', '--memory-limit=1G',
            ],
            dirname(__DIR__, 2),
            timeout: 300,
        );
        $process->run();

        $result = json_decode($process->getOutput(), true);
        $this->assertIsArray($result, 'PHPStan did not run: ' . $process->getErrorOutput() . $process->getOutput());

        $messages = $result['errors'];
        foreach ($result['files'] as $file => $errors) {
            foreach ($errors['messages'] as $message) {
                $messages[] = basename($file) . ':' . $message['line'] . ': ' . $message['message'];
            }
        }

        // The one dumpType() call of the data file shows that PHPStan analysed it.
        $dumped = array_filter($messages, fn (string $message): bool => str_contains($message, ': Dumped type: '));
        $this->assertCount(1, $dumped, implode("\n", $messages));
        $this->assertSame([], array_values(array_diff($messages, $dumped)));
    }
}
