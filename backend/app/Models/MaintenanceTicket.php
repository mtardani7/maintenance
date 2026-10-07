<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceTicket extends Model
{
    protected $fillable = [
        'ticket_number', 'plant_id', 'machine_id', 'problem_type', 'description',
        'source', 'status', 'priority', 'reported_by',
        'duration_hours',
        'solution',
        'reason',
        'action_taken',
        'executor_id',
        'root_cause_analysis',
        'corrective_action_plan',
        'target_at',
        'action_by_id',
        'verification_checklist',
        'closed_at',
        'closed_by_id',
    ];

    protected $casts = [
        'duration_hours' => 'float',
        'closed_at' => 'datetime',
        'target_at' => 'datetime',
        'verification_checklist' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'ticket_number';
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query->where(function ($ticketQuery) use ($value): void {
            $ticketQuery->where('ticket_number', $value);

            if (ctype_digit((string) $value)) {
                $ticketQuery->orWhere('id', (int) $value);
            }
        });
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executor_id');
    }

    public function actionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'action_by_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function spareParts(): HasMany
    {
        return $this->hasMany(MaintenanceTicketSparePart::class, 'maintenance_ticket_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
