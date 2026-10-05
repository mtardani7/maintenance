<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MonthlyMaintenanceReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly array $report)
    {
    }

    public function build(): static
    {
        return $this
            ->subject('Monthly Maintenance Report - '.$this->report['period'])
            ->html($this->renderHtml());
    }

    private function renderHtml(): string
    {
        $rows = [
            'Closed tickets' => number_format($this->report['closed_ticket_count']),
            'Total Production Downtime (hours)' => number_format($this->report['total_production_downtime'], 2),
            'Total Maintenance Resolution Duration (hours)' => number_format($this->report['total_maintenance_resolution_duration'], 2),
            'Total Production Time (hours)' => number_format($this->report['total_production_time'], 2),
            'Downtime %' => number_format($this->report['downtime_percentage'], 2).'%',
        ];

        $tableRows = collect($rows)->map(fn (string $value, string $label): string => sprintf(
            '<tr><td style="border-bottom:1px solid #e2e8f0;padding:10px 12px;color:#475569">%s</td><td style="border-bottom:1px solid #e2e8f0;padding:10px 12px;text-align:right;font-weight:600;color:#0f172a">%s</td></tr>',
            e($label),
            e($value),
        ))->implode('');

        return sprintf(
            '<!doctype html><html><body style="margin:0;background:#f8fafc;color:#0f172a;font-family:Arial,sans-serif"><div style="max-width:680px;margin:32px auto;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:28px"><p style="margin:0;color:#0f766e;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">MIRA — Maintenance Improvement Report Analysis</p><h1 style="margin:8px 0;color:#0f172a;font-size:24px">Monthly Maintenance Report</h1><p style="margin:0 0 22px;color:#64748b">Period: %s (%s to %s)</p><table style="width:100%%;border-collapse:collapse;border-top:1px solid #e2e8f0">%s</table><p style="margin:22px 0 0;color:#64748b;font-size:12px">Production downtime is calculated from closed maintenance ticket duration. Production time uses the configured machine operating calendar.</p></div></body></html>',
            e($this->report['period']),
            e($this->report['period_start']),
            e($this->report['period_end']),
            $tableRows,
        );
    }
}
