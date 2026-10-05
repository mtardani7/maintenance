<?php

namespace App\Notifications;

use App\Models\MaintenanceTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MaintenanceTicketCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(private MaintenanceTicket $ticket, private int $incidentId) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'ticket_created',
            'title' => 'Ticket Baru',
            'message' => 'Ada ticket maintenance baru yang perlu ditangani.',
            'ticket_id' => $this->ticket->getKey(),
            'ticket_number' => $this->ticket->ticket_number,
            'machine' => $this->ticket->machine?->name,
            'problem' => $this->ticket->description ?: $this->ticket->problem_type,
            'incident_id' => $this->incidentId,
            'url' => '/tickets/'.$this->ticket->getKey(),
            'dedupe_key' => 'ticket-created:'.$this->ticket->getKey(),
        ];
    }
}
