<?php

namespace App\Events;

use App\Models\Incident;
use App\Models\MaintenanceTicket;

class TicketCreated
{
    public function __construct(public MaintenanceTicket $ticket, public Incident $incident) {}
}
