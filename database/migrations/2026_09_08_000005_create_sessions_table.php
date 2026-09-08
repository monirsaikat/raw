<?php

// Storage for SESSION_DRIVER=database (see DatabaseSessionHandler).

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id', 128)->primary();
            $table->longText('payload');
            $table->integer('last_activity')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
