<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->foreignId('maintenance_ticket_id')
                ->nullable()
                ->after('reported_by')
                ->constrained('maintenance_tickets')
                ->nullOnDelete();
        });

        // Recover prior automatic links only when every creation attribute and
        // the exact creation timestamp identify one unique operator ticket.
        DB::table('incidents')->whereNull('maintenance_ticket_id')->orderBy('id')->eachById(
            function (object $incident): void {
                $ticketDescription = $incident->description ?: $incident->problem_type;
                $matches = DB::table('maintenance_tickets')
                    ->where('source', 'OPERATOR')
                    ->where('machine_id', $incident->machine_id)
                    ->where('reported_by', $incident->reported_by)
                    ->where('problem_type', $incident->problem_type)
                    ->where('description', $ticketDescription)
                    ->where('created_at', $incident->created_at)
                    ->limit(2)
                    ->pluck('id');

                if ($matches->count() === 1) {
                    DB::table('incidents')
                        ->where('id', $incident->id)
                        ->update(['maintenance_ticket_id' => $matches->first()]);
                }
            },
        );
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('maintenance_ticket_id');
        });
    }
};
