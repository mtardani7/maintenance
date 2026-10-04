<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 50)->nullable()->after('email');
            $table->string('nik', 100)->nullable()->after('phone');
            $table->boolean('is_active')->default(true)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'nik', 'is_active']);
        });
    }
};
