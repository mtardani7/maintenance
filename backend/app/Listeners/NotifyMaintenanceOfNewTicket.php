<?php

namespace App\Listeners;

use App\Events\TicketCreated;
use App\Models\User;
use App\Notifications\MaintenanceTicketCreatedNotification;

class NotifyMaintenanceOfNewTicket
{
    public function handle(TicketCreated $event): void
    {
        $ticket = $event->ticket->loadMissing('machine');
        $recipients = User::query()->where('role', 'technician')->where('is_active', true);
        if ($ticket->action_by_id) $recipients->whereKey($ticket->action_by_id);

        $recipients->each(function (User $user) use ($ticket, $event): void {
            $key = 'ticket-created:'.$ticket->getKey();
            $exists = $user->notifications()->where('type', MaintenanceTicketCreatedNotification::class)->where('data->dedupe_key', $key)->exists();
            if (! $exists) $user->notify(new MaintenanceTicketCreatedNotification($ticket, $event->incident->getKey()));
        });
    }
}
