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
}
