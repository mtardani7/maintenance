<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tickets', function (Blueprint $table): void {
            $table->dateTime('actual_downtime_started_at')->nullable();
            $table->dateTime('actual_downtime_ended_at')->nullable();
        });

        Schema::create('maintenance_ticket_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->text('reason')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->timestamps();
            $table->index(['maintenance_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_ticket_audits');
        Schema::table('maintenance_tickets', function (Blueprint $table): void {
            $table->dropColumn(['actual_downtime_started_at', 'actual_downtime_ended_at']);
        });
    }
};
