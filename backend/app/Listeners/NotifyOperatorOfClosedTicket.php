<?php

namespace App\Listeners;

use App\Events\TicketClosed;
use App\Models\Incident;
use App\Notifications\MaintenanceTicketClosedNotification;

class NotifyOperatorOfClosedTicket
{
    public function handle(TicketClosed $event): void
    {
        $ticket = $event->ticket->loadMissing(['machine', 'closedBy']);
        $incident = Incident::query()->where('maintenance_ticket_id', $ticket->getKey())->first();
        $operator = $incident?->reporter;
        if (! $operator || $operator->role !== 'operator') return;

        $key = 'ticket-closed:'.$ticket->getKey();
        $exists = $operator->notifications()->where('type', MaintenanceTicketClosedNotification::class)->where('data->dedupe_key', $key)->exists();
        if (! $exists) $operator->notify(new MaintenanceTicketClosedNotification($ticket->setRelation('incident', $incident)));
    }
}
