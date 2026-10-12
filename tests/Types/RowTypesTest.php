<?php

declare(strict_types=1);

namespace Tests\Types;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * PHPStan, with Larastan, reads each row of QueryBuilder as an associative array, as the builder returns it, and the
 * base query of a ClickHouse model as a QueryBuilder. The assertions are the assertType() calls in data/row-types.php.
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
