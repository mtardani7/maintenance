<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('incidents')
            ->where('status', 'OPEN')
            ->whereIn('maintenance_ticket_id', function ($query): void {
                $query->select('id')
                    ->from('maintenance_tickets')
                    ->where('status', 'CLOSED');
            })
            ->update(['status' => 'RESOLVED']);
    }

    public function down(): void
    {
        // A completed ticket should not reopen its linked incident on rollback.
    }
};
