<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Schema\ColumnDefinition;

/**
 * A column of a SchemaBlueprint, with the ClickHouse column modifiers that
 * SchemaGrammar compiles.
 *
 * The modifiers are plain attributes, as Laravel's are, so they also work on
 * the columns of Laravel's own Blueprint; this class only describes them.
 *
 * @method $this lowCardinality(bool $value = true) Store the column as LowCardinality(<type>), LowCardinality(Nullable(<type>)) when it is nullable
 * @method $this codec(string|Expression $codec) Compress the column with the given codecs: codec('ZSTD(3)') gives CODEC(ZSTD(3))
 * @method $this ttl(string|Expression $expression) Reset the column to its default after the TTL expression, such as toDateTime(created_at) + INTERVAL 1 DAY
 * @method $this ephemeral(mixed $default = true) Make the column EPHEMERAL: not stored, but readable by the defaults of other columns while a row is inserted
 * @method $this timezone(string $timezone) Give a date-time column a time zone: DateTime('UTC'), DateTime64(3, 'UTC')
 * @method $this renameTo(string $column) With change(), rename the column after its new definition is applied: ALTER TABLE ... RENAME COLUMN ... TO ...
 */
class SchemaColumnDefinition extends ColumnDefinition
{
    //
}
