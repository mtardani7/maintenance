<?php

namespace App\Notifications;

use App\Models\MaintenanceTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MaintenanceTicketClosedNotification extends Notification
{
    use Queueable;

    public function __construct(private MaintenanceTicket $ticket) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'ticket_closed',
            'title' => 'Ticket Selesai',
            'message' => "Ticket {$this->ticket->ticket_number} telah diselesaikan oleh Maintenance.",
            'ticket_id' => $this->ticket->getKey(),
            'ticket_number' => $this->ticket->ticket_number,
            'machine' => $this->ticket->machine?->name,
            'solution' => $this->ticket->solution,
            'closed_by' => $this->ticket->closedBy?->name,
            'closed_at' => $this->ticket->closed_at?->toISOString(),
            'incident_id' => $this->ticket->incident?->getKey(),
            'url' => '/tickets/'.$this->ticket->getKey(),
            'dedupe_key' => 'ticket-closed:'.$this->ticket->getKey(),
        ];
    }
}
