<?php

namespace App\Providers;

use App\Events\TicketClosed;
use App\Events\TicketCreated;
use App\Listeners\NotifyMaintenanceOfNewTicket;
use App\Listeners\NotifyOperatorOfClosedTicket;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(TicketCreated::class, NotifyMaintenanceOfNewTicket::class);
        Event::listen(TicketClosed::class, NotifyOperatorOfClosedTicket::class);
    }
}
