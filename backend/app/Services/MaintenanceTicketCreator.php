<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\MaintenanceTicket;
use Illuminate\Support\Facades\DB;

class MaintenanceTicketCreator
{
    public function create(array $data): MaintenanceTicket
    {
        return DB::transaction(function () use ($data): MaintenanceTicket {
            $machine = Machine::query()->lockForUpdate()->findOrFail($data['machine_id']);
            $plant = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $machine->plant?->code ?? $machine->plant?->name ?? 'PLANT'));
            $machineCode = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $machine->code));
            $prefix = sprintf('%s-%s-', $plant, $machineCode);
            $lastSequence = MaintenanceTicket::query()
                ->where('ticket_number', 'like', $prefix.'%')
                ->pluck('ticket_number')
                ->map(fn (string $number): int => (int) str($number)->afterLast('-')->toString())
                ->max() ?? 0;

            $data['ticket_number'] = sprintf('%s%03d', $prefix, $lastSequence + 1);
            $data['status'] = 'OPEN';
            $data['priority'] ??= 'MEDIUM';

            return MaintenanceTicket::create($data)->load(['machine', 'reporter']);
        });
    }
}
