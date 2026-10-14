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
            ['machine', 'count', 'none'] => $this->machineCount($intent, $data),
            ['machine', 'count', 'plant'] => $this->machineCountByPlant($intent, $data),
            ['plant', 'count', 'none'] => $this->plantCount($intent, $data),
            ['ticket', 'count', 'none'] => $this->ticketCount($intent, $data, $period),
            ['incident', 'count', 'none'] => $this->incidentCount($intent, $data, $period),
            ['ticket', 'status', 'none'] => $this->ticketStatus($data),
            ['incident', 'count', 'machine'] => $this->machineRanking($intent, $data, $period),
            ['incident', 'count', 'plant'] => $this->plantRanking($intent, $data, $period),
            ['incident', 'count', 'problem_type'] => $this->problemRanking($intent, $data, $period),
            ['spare_part', 'sum', 'none'] => $this->sparePartTotal($data, $period),
            ['spare_part', 'sum', 'spare_part'] => $this->sparePartRanking($intent, $data, $period),
            default => 'Tidak ada data yang ditemukan.',
        };
    }

    private function machineCount(array $intent, mixed $data): string
    {
        if (! is_numeric($data)) {
            return 'Data jumlah mesin tidak valid.';
        }

        $qualifier = isset($intent['filter']['is_active'])
            ? ((bool) $intent['filter']['is_active'] ? ' aktif' : ' tidak aktif')
            : '';
        $plant = $this->plantQualifier($intent);

        return 'Ada '.(int) $data." mesin{$qualifier}{$plant}.";
    }

    private function machineCountByPlant(array $intent, mixed $data): string
    {
        if (! is_array($data)) {
            return 'Data jumlah mesin per plant tidak valid.';
        }
        if ($data === []) {
            return 'Tidak ada data jumlah mesin per plant.';
        }

        $rows = collect($data)
            ->map(fn (array $row): string => ($row['plant'] ?? 'Plant tidak diketahui').': '.(int) ($row['count'] ?? 0).' mesin')
            ->implode('; ');

        return 'Jumlah mesin pada setiap plant: '.$rows.'.';
    }

    private function plantCount(array $intent, mixed $data): string
    {
        if (! is_numeric($data)) {
            return 'Data jumlah plant tidak valid.';
        }

        $qualifier = isset($intent['filter']['is_active'])
            ? ((bool) $intent['filter']['is_active'] ? ' aktif' : ' tidak aktif')
            : '';

        return 'Ada '.(int) $data." plant{$qualifier}.";
    }

    private function plantQualifier(array $intent): string
    {
        $plant = $intent['filter']['plant'] ?? null;

        return is_string($plant) && $plant !== '' ? ' di '.(str_starts_with(mb_strtolower($plant), 'plant') ? $plant : 'Plant '.$plant) : '';
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
        $qualifier = $this->plantQualifier($intent);
        if (isset($intent['filter']['machine'])) {
            $qualifier .= ' pada mesin '.$intent['filter']['machine'];
        }

        if ($count === 0) {
            $description = $statusText === '' ? 'tiket'.$qualifier : "tiket {$statusText}{$qualifier}";

            return "Tidak ada {$description} {$period}.";
        }

        $description = $statusText === '' ? 'tiket'.$qualifier : "tiket {$statusText}{$qualifier}";

        return ucfirst($period)." ada {$count} {$description}.";
    }

    private function incidentCount(array $intent, mixed $data, string $period): string
    {
        if (! is_numeric($data)) {
            return 'Tidak ada data yang ditemukan.';
        }

        $count = (int) $data;

        $qualifier = $this->plantQualifier($intent);
        if (isset($intent['filter']['machine'])) {
            $qualifier .= ' pada mesin '.$intent['filter']['machine'];
        }

        return $count === 0
            ? "Tidak ada laporan incident{$qualifier} {$period}."
            : ucfirst($period)." ada {$count} laporan incident{$qualifier}.";
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

    private function plantRanking(array $intent, mixed $data, string $period): string
    {
        if (! is_array($data) || ! isset($data[0]['plant'], $data[0]['count'])) {
            return 'Tidak ada data incident per plant yang ditemukan.';
        }

        $qualifier = ($intent['sort'] ?? 'desc') === 'asc' ? 'paling sedikit' : 'paling sering';

        return "Plant yang {$qualifier} mengalami incident adalah {$data[0]['plant']}, dengan ".(int) $data[0]['count']." kejadian dalam {$period}.";
    }

    private function sparePartTotal(mixed $data, string $period): string
    {
        if (! is_numeric($data)) {
            return 'Data kuantitas spare part tidak valid.';
        }

        return 'Total kuantitas spare part yang digunakan '.$period.' adalah '.(int) $data.'.';
    }

    private function sparePartRanking(array $intent, mixed $data, string $period): string
    {
        if (! is_array($data) || ! isset($data[0]['name'], $data[0]['quantity'])) {
            return 'Tidak ada data penggunaan spare part yang ditemukan.';
        }

        $qualifier = ($intent['sort'] ?? 'desc') === 'asc' ? 'paling sedikit' : 'paling banyak';

        return "Spare part {$qualifier} digunakan adalah {$data[0]['name']} (".$data[0]['material_code'].'), sebanyak '.(int) $data[0]['quantity']." unit dalam {$period}.";
    }
}
