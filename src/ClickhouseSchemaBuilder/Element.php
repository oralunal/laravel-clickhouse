<?php

namespace Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder;

interface Element
{
    public function compile(): string;
}