<?php

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue', 100);
            $table->longText('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
            $table->index(['queue', 'reserved_at', 'available_at']);
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('connection', 100);
            $table->string('queue', 100);
            $table->longText('payload');
            $table->text('exception');
            $table->dateTime('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
    }
};
