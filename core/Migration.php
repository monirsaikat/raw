<?php

// Base class for PHP migrations. A migration file returns an instance:
//
//   return new class extends Migration {
//       public function up(): void { Schema::create(...); }
//       public function down(): void { Schema::dropIfExists(...); }
//   };
//
// Set $connection to run against a non-default connection.

abstract class Migration
{
    protected ?string $connection = null;

    public function getConnection(): ?string
    {
        return $this->connection;
    }

    abstract public function up(): void;

    abstract public function down(): void;
}
