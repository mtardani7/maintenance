<?php

namespace App\Services;

class MiraResponseFormatter
{
    public function format(string $question, array $intent, array $queryResult): string
    {
        $data = $queryResult['data'] ?? null;
        $period = (string) ($queryResult['period'] ?? '');

        if (is_array($data) && $data === []) {
            return 'Tidak ada data yang ditemukan.';
        }

        return match ([$intent['entity'] ?? null, $intent['metric'] ?? null, $intent['group_by'] ?? null]) {
            ['ticket', 'count', 'none'] => $this->ticketCount($intent, $data, $period),
            ['incident', 'count', 'none'] => $this->incidentCount($data, $period),
            ['ticket', 'status', 'none'] => $this->ticketStatus($data),
            ['incident', 'count', 'machine'] => $this->machineRanking($intent, $data, $period),
            ['incident', 'count', 'problem_type'] => $this->problemRanking($intent, $data, $period),
            default => 'Tidak ada data yang ditemukan.',
        };
    }

    private function ticketCount(array $intent, mixed $data, string $period): string
    {
        if (! is_numeric($data)) {
            return 'Tidak ada data yang ditemukan.';
        }

        $count = (int) $data;
        $status = $intent['filter']['status'] ?? null;
        $statusText = match ($status) {
            'open' => 'yang masih terbuka',
            'closed' => 'yang sudah ditutup',
            'resolved' => 'yang sudah selesai',
            default => '',
        };

        if ($count === 0) {
            $description = $statusText === '' ? 'tiket' : "tiket {$statusText}";

            return $status === 'open' && $period === 'hari ini'
                ? 'Tidak ada tiket yang masih terbuka hari ini.'
                : "Tidak ada {$description} {$period}.";
        }

        $description = $statusText === '' ? 'tiket' : "tiket {$statusText}";

        return ucfirst($period)." ada {$count} {$description}.";
    }

    private function incidentCount(mixed $data, string $period): string
    {
        if (! is_numeric($data)) {
            return 'Tidak ada data yang ditemukan.';
        }

        $count = (int) $data;

        return $count === 0
            ? "Tidak ada laporan insiden {$period}."
            : ucfirst($period)." ada {$count} laporan insiden.";
    }

    private function ticketStatus(mixed $data): string
    {
        if (! is_array($data) || $data === []) {
            return 'Tidak ada data yang ditemukan.';
        }

        $statuses = collect($data)
            ->map(fn (array $row): string => ($row['status'] ?? 'status tidak diketahui').': '.(int) ($row['count'] ?? 0))
            ->implode(', ');

        return 'Ringkasan status tiket: '.$statuses.'.';
    }

    private function machineRanking(array $intent, mixed $data, string $period): string
    {
        if (! is_array($data) || ! isset($data[0]['machine'], $data[0]['count'])) {
            return 'Tidak ada data yang ditemukan.';
        }

        $qualifier = ($intent['sort'] ?? 'desc') === 'asc' ? 'paling sedikit' : 'paling sering';
        $machine = (string) $data[0]['machine'];
        $count = (int) $data[0]['count'];

        return "Mesin yang {$qualifier} mengalami masalah adalah {$machine}, dengan {$count} kejadian dalam {$period}.";
    }

    private function problemRanking(array $intent, mixed $data, string $period): string
    {
        if (! is_array($data) || ! isset($data[0]['problem_type'], $data[0]['count'])) {
            return 'Tidak ada data yang ditemukan.';
        }

        $problem = (string) $data[0]['problem_type'];
        $count = (int) $data[0]['count'];
        $machine = $intent['filter']['machine'] ?? null;
        $machineText = is_string($machine) && $machine !== '' ? " pada {$machine}" : '';

        return "Masalah yang paling sering terjadi{$machineText} adalah {$problem}, sebanyak {$count} kejadian dalam {$period}.";
    }
}
