<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tickets = $this->tickets();

        DB::transaction(function () use ($tickets): void {
            foreach ($tickets as $ticket) {
                DB::table('maintenance_tickets')->where('id', $ticket->id)
                    ->update(['ticket_number' => '__ticket_reformat__'.$ticket->id]);
            }

            $sequences = [];
            foreach ($tickets as $ticket) {
                $plant = $this->code($ticket->plant_code ?? $ticket->plant_name ?? 'PLANT');
                $machine = $this->code($ticket->machine_code ?? $ticket->machine_id);
                $key = $plant.'-'.$machine;
                $sequence = ($sequences[$key] ?? 0) + 1;
                $sequences[$key] = $sequence;

                DB::table('maintenance_tickets')->where('id', $ticket->id)
                    ->update(['ticket_number' => sprintf('%s-%s-%03d', $plant, $machine, $sequence)]);
            }
        });
    }

    public function down(): void
    {
        $tickets = $this->tickets();

        DB::transaction(function () use ($tickets): void {
            foreach ($tickets as $ticket) {
                DB::table('maintenance_tickets')->where('id', $ticket->id)
                    ->update(['ticket_number' => '__ticket_restore__'.$ticket->id]);
            }

            $sequences = [];
            foreach ($tickets as $ticket) {
                $plant = $this->code($ticket->plant_code ?? $ticket->plant_name ?? 'PLANT');
                $machine = $this->code($ticket->machine_code ?? $ticket->machine_id);
                $date = date('dmy', strtotime($ticket->created_at ?? 'now'));
                $key = $plant.'-'.$date.'-'.$machine;
                $sequence = ($sequences[$key] ?? 0) + 1;
                $sequences[$key] = $sequence;

                DB::table('maintenance_tickets')->where('id', $ticket->id)
                    ->update(['ticket_number' => sprintf('%s-%s-%s-%03d', $plant, $date, $machine, $sequence)]);
            }
        });
    }

    private function tickets(): array
    {
        return DB::table('maintenance_tickets as ticket')
            ->leftJoin('plants', 'plants.id', '=', 'ticket.plant_id')
            ->leftJoin('machines', 'machines.id', '=', 'ticket.machine_id')
            ->orderBy('ticket.id')
            ->get([
                'ticket.id', 'ticket.plant_id', 'ticket.machine_id', 'ticket.created_at',
                'plants.code as plant_code', 'plants.name as plant_name', 'machines.code as machine_code',
            ])
            ->all();
    }

    private function code(string|int $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]+/i', '', (string) $value) ?: 'X');
    }
};
