<?php

namespace App\Jobs;

use App\Mail\MonthlyMaintenanceReportMail;
use App\Models\Machine;
use App\Models\MaintenanceTicket;
use App\Support\SystemSettings;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class GenerateMonthlyMaintenanceReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly ?string $month = null, public readonly bool $manual = false)
    {
        $this->onConnection(config('queue.monthly_maintenance_report.connection'));
        $this->onQueue(config('queue.monthly_maintenance_report.queue', 'default'));
    }

    public function handle(): void
    {
        $periodStart = $this->month
            ? CarbonImmutable::parse($this->month)->startOfMonth()
            : CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();
        $periodEnd = $periodStart->endOfMonth();
        $productionHoursPerMachinePerDay = config('queue.monthly_maintenance_report.production_hours_per_machine_per_day');

        if (! is_numeric($productionHoursPerMachinePerDay) || (float) $productionHoursPerMachinePerDay <= 0) {
            throw new RuntimeException('MAINTENANCE_PRODUCTION_HOURS_PER_MACHINE_PER_DAY must be configured with a value greater than zero.');
        }

        $closedTickets = MaintenanceTicket::query()
            ->where('status', 'CLOSED')
            ->whereBetween('closed_at', [$periodStart, $periodEnd])
            ->get(['duration_hours']);
        $totalMaintenanceResolutionDuration = (float) $closedTickets->sum('duration_hours');
        $totalProductionDowntime = $totalMaintenanceResolutionDuration;
        $totalProductionTime = Machine::query()->count()
            * $periodStart->daysInMonth
            * (float) $productionHoursPerMachinePerDay;
        $downtimePercentage = $totalProductionTime > 0
            ? ($totalProductionDowntime / $totalProductionTime) * 100
            : 0.0;

        $report = [
            'period' => $periodStart->format('F Y'),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'closed_ticket_count' => $closedTickets->count(),
            'total_production_downtime' => $totalProductionDowntime,
            'total_maintenance_resolution_duration' => $totalMaintenanceResolutionDuration,
            'total_production_time' => $totalProductionTime,
            'downtime_percentage' => $downtimePercentage,
        ];

        $recipients = $this->recipients();
        if ($recipients === []) {
            throw new RuntimeException('No valid monthly maintenance report recipients are configured.');
        }

        Mail::to($recipients)->send(new MonthlyMaintenanceReportMail($report));
        $settings = SystemSettings::get('email.monthly_report', []);
        $settings['first_sent_at'] ??= now()->toIso8601String();
        $settings['last_sent_month'] = $periodStart->format('Y-m');
        SystemSettings::put('email.monthly_report', $settings);
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $stored = SystemSettings::get('email.monthly_report', []);
        $source = isset($stored['recipients']) ? $stored['recipients'] : config('mail.monthly_maintenance_report.recipients', []);
        return collect($source)
            ->flatMap(fn (mixed $emails): array => is_array($emails)
                ? $emails
                : preg_split('/\s*,\s*/', (string) $emails, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (mixed $email): string => trim((string) $email))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }
}
