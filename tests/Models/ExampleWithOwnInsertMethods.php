<?php

namespace Tests\Models;

use LogicException;
use Oralunal\LaravelClickHouse\BaseModel;

/**
 * A model with methods of its own named like BaseModel's insert helpers, with other signatures.
 *
 * It loads only while the helpers are private, and BaseModel must never call these methods.
 */
class ExampleWithOwnInsertMethods extends BaseModel
{
    protected $table = 'own_insert_method_rows';

    /**
     * @param string $csv
     * @return void
     */
    public static function insertRows(string $csv): void
    {
        throw new LogicException("BaseModel called the model's own insertRows()");
    }

    /**
     * @return int
     */
    public function insertAssocRows(): int
    {
        throw new LogicException("BaseModel called the model's own insertAssocRows()");
    }
}
