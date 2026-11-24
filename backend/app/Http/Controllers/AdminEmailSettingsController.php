<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateMonthlyMaintenanceReport;
use App\Models\User;
use App\Models\Plant;
use App\Support\SystemSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminEmailSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $ticket = SystemSettings::get('email.ticket', []);
        $monthly = SystemSettings::get('email.monthly_report', []);
        $ticket['enabled'] ??= false;
        $ticket['maintenance_enabled'] ??= true;
        $ticket['supervisor_enabled'] ??= true;
        $ticket['plant_recipients'] = $this->plantRecipients();
        $monthly['recipients'] ??= $this->configuredReportEmails();
        $monthly['auto_enabled'] ??= false;
        $monthly['first_sent_at'] ??= null;
        $monthly['last_sent_month'] ??= null;

        return response()->json([
            'ticket' => $ticket,
            'monthly_report' => $monthly,
            'mailer_ready' => $this->mailerReady(),
            'mailer' => config('mail.default'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'ticket.enabled' => ['required', 'boolean'],
            'ticket.maintenance_enabled' => ['required', 'boolean'],
            'ticket.supervisor_enabled' => ['required', 'boolean'],
            'monthly_report.auto_enabled' => ['required', 'boolean'],
            'monthly_report.recipients' => ['array'],
            'monthly_report.recipients.*' => ['email', 'max:255'],
        ]);
        $old = SystemSettings::get('email.monthly_report', []);
        SystemSettings::put('email.ticket', $data['ticket']);
        SystemSettings::put('email.monthly_report', [
            ...$data['monthly_report'],
            'recipients' => array_values(array_unique($data['monthly_report']['recipients'] ?? [])),
            'first_sent_at' => $old['first_sent_at'] ?? null,
            'last_sent_month' => $old['last_sent_month'] ?? null,
        ]);
        return $this->show($request);
    }

    public function sendReport(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($this->mailerReady(), 422, 'Mailer belum dikonfigurasi untuk mengirim email. Atur SMTP pada konfigurasi server terlebih dahulu.');
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $settings = SystemSettings::get('email.monthly_report', []);
        $recipients = $settings['recipients'] ?? $this->configuredReportEmails();
        abort_if(count($recipients) === 0, 422, 'Tambahkan minimal satu penerima email laporan.');
        GenerateMonthlyMaintenanceReport::dispatch($data['month'] ?? null, true);
        return response()->json(['message' => 'Laporan sedang dikirim. Status pengiriman akan diperbarui setelah email berhasil diproses.'], 202);
    }

    private function authorizeAdmin(Request $request): void { abort_unless($request->user()?->role === 'admin', 403); }

    private function plantRecipients(): array
    {
        $users = User::query()->where('is_active', true)->whereIn('role', ['technician', 'supervisor'])
            ->get(['plant_id', 'role', 'name', 'email']);

        return Plant::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
            ->map(function (Plant $plant) use ($users): array {
                $forRole = fn (string $role): array => $users->where('plant_id', $plant->id)->where('role', $role)
                    ->filter(fn (User $user) => filter_var($user->email, FILTER_VALIDATE_EMAIL) && ! preg_match('/@(example\.(com|net|org)|localhost)$/i', $user->email))
                    ->map(fn (User $user): array => ['name' => $user->name, 'email' => $user->email])
                    ->unique('email')->values()->all();

                return ['plant_id' => $plant->id, 'code' => $plant->code, 'name' => $plant->name,
                    'maintenance' => $forRole('technician'), 'supervisors' => $forRole('supervisor')];
            })->all();
    }

    private function configuredReportEmails(): array
    {
        return collect(config('mail.monthly_maintenance_report.recipients', []))->flatMap(fn ($v) => is_array($v) ? $v : preg_split('/\s*,\s*/', (string) $v, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
    }

    private function mailerReady(): bool
    {
        $mailer = config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) return false;
        if ($mailer === 'smtp') return filled(config('mail.mailers.smtp.host')) && config('mail.mailers.smtp.host') !== '127.0.0.1' && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
        return true;
    }
}
