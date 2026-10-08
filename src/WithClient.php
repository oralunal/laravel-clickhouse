<?php

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Client;
use Illuminate\Support\Facades\DB;

trait WithClient
{
    public function getThisClient(): Client
    {
        return $this->resolveConnection()->getClient();
    }

    /**
     * @return Client
     * @deprecated use $this->getThisClient() instead
     */
    public static function getClient(): Client
    {
        return (new static())->getThisClient();
    }

    public function resolveConnection(): Connection
    {
        return DB::connection($this->connection);
    }
}