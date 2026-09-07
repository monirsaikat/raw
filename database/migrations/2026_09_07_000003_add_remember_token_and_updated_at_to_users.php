<?php

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('remember_token', 100)->nullable()->after('password');
            $table->dateTime('updated_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['remember_token', 'updated_at']);
        });
    }
};
