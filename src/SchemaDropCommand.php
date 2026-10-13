<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use Illuminate\Support\Fluent;

/**
 * The command of SchemaBlueprint::drop() and dropIfExists(), with the
 * ClickHouse modifier that SchemaGrammar compiles.
 *
 * The modifier is a plain attribute, as Laravel's modifiers are; this class
 * only describes it, so that static analysis tools know it.
 *
 * @method $this sync(bool $value = true) Wait until ClickHouse has removed the data of the table: DROP TABLE <table> SYNC (see SchemaBuilder::dropSync())
 *
 * @extends Fluent<string, mixed>
 */
class SchemaDropCommand extends Fluent
{
    //
}
