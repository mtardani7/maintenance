<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $appends = ['url'];

    protected $fillable = [
        'incident_id', 'maintenance_ticket_id', 'uploaded_by',
        'file_name', 'file_path', 'mime_type', 'file_size',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function maintenanceTicket(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTicket::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): string
    {
        return '/attachments/'.$this->id;
    }
}
