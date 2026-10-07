<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('maintenance_ticket_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->timestamps();
            $table->index(['incident_id', 'maintenance_ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
