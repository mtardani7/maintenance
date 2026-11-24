<?php

namespace App\Jobs;

use App\Mail\MaintenanceTicketEmail;
use App\Models\MaintenanceTicket;
use App\Support\SystemSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendNewTicketEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $ticketId) {}

    public function handle(): void
    {
        $mailer = config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) return;
        if ($mailer === 'smtp' && (blank(config('mail.mailers.smtp.host')) || config('mail.mailers.smtp.host') === '127.0.0.1')) return;
        $settings = SystemSettings::get('email.ticket', []);
        if (! ($settings['enabled'] ?? false)) return;
        $recipients = [];
        $ticket = MaintenanceTicket::query()->with(['machine.plant'])->findOrFail($this->ticketId);
        $plantUsers = \App\Models\User::query()->where('plant_id', $ticket->plant_id)->where('is_active', true)
            ->whereIn('role', ['technician', 'supervisor'])->get(['role', 'email']);
        if ($settings['maintenance_enabled'] ?? true) {
            $recipients = [...$recipients, ...$plantUsers->where('role', 'technician')->pluck('email')->all()];
        }
        if ($settings['supervisor_enabled'] ?? true) {
            $recipients = [...$recipients, ...$plantUsers->where('role', 'supervisor')->pluck('email')->all()];
        }
        $recipients = collect($recipients)->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL)
            && ! preg_match('/@(example\.(com|net|org)|localhost)$/i', $email))->unique()->values()->all();
        if ($recipients === []) return;

        Mail::to($recipients)->send(new MaintenanceTicketEmail([
            'number' => $ticket->ticket_number ?? $ticket->number ?? (string) $ticket->getKey(),
            'machine' => $ticket->machine?->name ?? '—',
            'plant' => $ticket->machine?->plant?->name ?? '—',
            'description' => $ticket->description ?? '—',
        ]));
    }
}
