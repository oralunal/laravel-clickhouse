<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use Illuminate\Database\Schema\IndexDefinition;

/**
 * A data-skipping index of a SchemaBlueprint, from index(), rawIndex(),
 * fullText() or vectorIndex(), with the ClickHouse index modifiers that
 * SchemaGrammar compiles.
 *
 * The modifiers are plain attributes, as Laravel's are; this class only
 * describes them, so that static analysis tools know them.
 *
 * @method $this granularity(int $granularity) Set the number of granules in each block of the index: GRANULARITY <n>. Without it, ClickHouse uses 1
 * @method $this materialize(bool $value = true) In Schema::table(), build the new index for the rows that the table already holds: ALTER TABLE ... MATERIALIZE INDEX <name>
 */
class SchemaIndexDefinition extends IndexDefinition
{
    //
}
