<?php

/**
 * Write docs/reference/api.md from the code: `composer docs:api`.
 */

use Tests\Unit\Documentation\ApiReference;

require __DIR__ . '/../../../vendor/autoload.php';

file_put_contents(ApiReference::PAGE, ApiReference::generate());

echo 'Wrote docs/reference/api.md', PHP_EOL;
