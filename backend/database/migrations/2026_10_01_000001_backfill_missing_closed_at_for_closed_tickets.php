<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Older close requests could update the ticket status without persisting
        // closed_at. For those historical rows, updated_at records that close.
        DB::table('maintenance_tickets')
            ->where('status', 'CLOSED')
            ->whereNull('closed_at')
            ->update(['closed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        // Preserve recovered close timestamps when rolling back this data fix.
    }
};
