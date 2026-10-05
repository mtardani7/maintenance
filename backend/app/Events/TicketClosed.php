<?php

namespace App\Events;

use App\Models\MaintenanceTicket;

class TicketClosed
{
    public function __construct(public MaintenanceTicket $ticket) {}
}
