<?php

namespace Tests\Unit;

use App\Services\MiraResponseFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MiraResponseFormatterTest extends TestCase
{
    #[DataProvider('ticketCountCases')]
    public function test_it_formats_open_ticket_counts_from_query_data(int $count, string $expected): void
    {
        $answer = app(MiraResponseFormatter::class)->format(
            'Berapa tiket open hari ini?',
            [
                'entity' => 'ticket',
                'metric' => 'count',
                'group_by' => 'none',
                'period' => 'today',
                'date_basis' => 'created_at',
                'filter' => ['status' => 'open'],
            ],
            ['period' => 'hari ini', 'data' => $count],
        );

        $this->assertSame($expected, $answer);
    }

    public static function ticketCountCases(): array
    {
        return [
            'no open tickets' => [0, 'Tidak ada tiket yang masih terbuka hari ini.'],
            'three open tickets' => [3, 'Hari ini ada 3 tiket yang masih terbuka.'],
            'four open tickets' => [4, 'Hari ini ada 4 tiket yang masih terbuka.'],
        ];
    }

    public function test_it_formats_machine_rankings_using_query_data(): void
    {
        $answer = app(MiraResponseFormatter::class)->format(
            'Mesin mana yang paling sering mengalami incident?',
            ['entity' => 'incident', 'metric' => 'count', 'group_by' => 'machine', 'sort' => 'desc'],
            [
                'period' => '30 hari terakhir',
                'data' => [['machine' => 'M-01 - Press 1', 'count' => 7]],
            ],
        );

        $this->assertSame(
            'Mesin yang paling sering mengalami masalah adalah M-01 - Press 1, dengan 7 kejadian dalam 30 hari terakhir.',
            $answer,
        );
    }

    public function test_it_returns_no_data_message_for_empty_results(): void
    {
        $answer = app(MiraResponseFormatter::class)->format(
            'Mesin mana yang sering mengalami incident?',
            ['entity' => 'incident', 'metric' => 'count', 'group_by' => 'machine'],
            ['period' => '30 hari terakhir', 'data' => []],
        );

        $this->assertSame('Tidak ada data yang ditemukan.', $answer);
    }

    public function test_it_formats_machine_and_plant_counts_from_query_values(): void
    {
        $formatter = app(MiraResponseFormatter::class);

        $machines = $formatter->format('Berapa mesin aktif di Plant 3?', [
            'entity' => 'machine', 'metric' => 'count', 'group_by' => 'none',
            'filter' => ['is_active' => true, 'plant' => '3'],
        ], ['data' => 24]);
        $plants = $formatter->format('Ada berapa plant?', [
            'entity' => 'plant', 'metric' => 'count', 'group_by' => 'none', 'filter' => [],
        ], ['data' => 4]);

        $this->assertSame('Ada 24 mesin aktif di Plant 3.', $machines);
        $this->assertSame('Ada 4 plant.', $plants);
    }

    public function test_it_formats_incident_count_and_plant_ranking(): void
    {
        $formatter = app(MiraResponseFormatter::class);
        $count = $formatter->format('Berapa incident di Plant 3?', [
            'entity' => 'incident', 'metric' => 'count', 'group_by' => 'none',
            'filter' => ['plant' => '3'],
        ], ['period' => 'hari ini', 'data' => 2]);
        $ranking = $formatter->format('Plant mana yang paling sedikit incident?', [
            'entity' => 'incident', 'metric' => 'count', 'group_by' => 'plant', 'sort' => 'asc',
        ], ['period' => '30 hari terakhir', 'data' => [['plant' => 'PLANT-3 - Plant 3', 'count' => 1]]]);

        $this->assertSame('Hari ini ada 2 laporan incident di Plant 3.', $count);
        $this->assertSame('Plant yang paling sedikit mengalami incident adalah PLANT-3 - Plant 3, dengan 1 kejadian dalam 30 hari terakhir.', $ranking);
    }

    public function test_it_formats_spare_part_total_and_most_used_item(): void
    {
        $formatter = app(MiraResponseFormatter::class);
        $total = $formatter->format('Berapa total spare part yang digunakan?', [
            'entity' => 'spare_part', 'metric' => 'sum', 'group_by' => 'none',
        ], ['period' => '30 hari terakhir', 'data' => 12]);
        $ranking = $formatter->format('Spare part apa yang paling sering digunakan?', [
            'entity' => 'spare_part', 'metric' => 'sum', 'group_by' => 'spare_part', 'sort' => 'desc',
        ], ['period' => '30 hari terakhir', 'data' => [['name' => 'Bearing', 'material_code' => 'BR-1', 'quantity' => 8]]]);

        $this->assertSame('Total kuantitas spare part yang digunakan 30 hari terakhir adalah 12.', $total);
        $this->assertSame('Spare part paling banyak digunakan adalah Bearing (BR-1), sebanyak 8 unit dalam 30 hari terakhir.', $ranking);
    }
}
