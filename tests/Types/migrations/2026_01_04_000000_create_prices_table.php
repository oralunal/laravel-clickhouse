<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A table of another connection: Larastan reads it with its own rules.
 */
return new class extends Migration
{
    protected $connection = 'sqlite';

    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount');
        });
    }
};
