<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('plant_id')->nullable()->constrained('plants')->nullOnDelete();
        });

        $defaultPlantId = DB::table('plants')->where('code', 'RX03')->value('id');
        if ($defaultPlantId !== null) {
            DB::table('users')->where('role', '<>', 'admin')->whereNull('plant_id')->update(['plant_id' => $defaultPlantId]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plant_id');
        });
    }
};
