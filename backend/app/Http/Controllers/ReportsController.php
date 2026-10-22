<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class ReportsController extends Controller
{
    private function filters(Request $request): array
    {
        abort_unless(in_array($request->user()?->role, ['supervisor', 'qa', 'admin'], true), 403);
        $data = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'plant_id' => ['nullable', 'integer', 'exists:plants,id'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'category' => ['nullable', 'in:summary,tickets,incidents,machines,spare-parts,downtime'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'in:newest,oldest,incidents_desc,incidents_asc'],
        ]);
        if (! empty($data['machine_id']) && ! empty($data['plant_id'])) {
            abort_unless(DB::table('machines')->where('id', $data['machine_id'])->where('plant_id', $data['plant_id'])->exists(), 422, 'Mesin tidak termasuk dalam plant yang dipilih.');
        }
        $data['start_date'] ??= now('Asia/Jakarta')->subDays(29)->toDateString();
        $data['end_date'] ??= now('Asia/Jakarta')->toDateString();
        abort_if(Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) > 1826, 422, 'Rentang laporan maksimal lima tahun.');

        return $data;
    }

    private function withinPeriod(Builder $query, string $column, array $filters): Builder
    {
        $from = Carbon::parse($filters['start_date'], 'Asia/Jakarta')->startOfDay()->utc();
        $until = Carbon::parse($filters['end_date'], 'Asia/Jakarta')->startOfDay()->addDay()->utc();

        return $query->where($column, '>=', $from)->where($column, '<', $until);
    }

    private function tickets(array $filters, bool $inPeriod = true): Builder
    {
        $query = DB::table('maintenance_tickets as t')
            ->leftJoin('plants as p', 'p.id', '=', 't.plant_id')
            ->leftJoin('machines as m', 'm.id', '=', 't.machine_id')
            ->leftJoin('users as u', 'u.id', '=', 't.executor_id')
            ->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('t.plant_id', $id))
            ->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('t.machine_id', $id));

        return $inPeriod ? $this->withinPeriod($query, 't.created_at', $filters) : $query;
    }

    private function incidents(array $filters): Builder
    {
        $query = DB::table('incidents as i')
            ->leftJoin('plants as p', 'p.id', '=', 'i.plant_id')
            ->leftJoin('machines as m', 'm.id', '=', 'i.machine_id')
            ->leftJoin('maintenance_tickets as t', 't.id', '=', 'i.maintenance_ticket_id')
            ->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('i.plant_id', $id))
            ->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('i.machine_id', $id));

        return $this->withinPeriod($query, 'i.created_at', $filters);
    }

    private function series(Builder $query, string $column, string $alias, array $filters, bool $monthly): array
    {
        $driver = DB::connection()->getDriverName();
        $key = $monthly
            ? ($driver === 'sqlite' ? "strftime('%Y-%m', datetime({$column}, '+7 hours'))" : "TO_CHAR(DATE_TRUNC('month', {$column} + INTERVAL '7 hours'), 'YYYY-MM')")
            : ($driver === 'sqlite' ? "DATE(datetime({$column}, '+7 hours'))" : "CAST(({$column} + INTERVAL '7 hours') AS DATE)");
        $items = $query->selectRaw("{$key} as period, COUNT(*) as total")
            ->groupByRaw($key)->orderBy('period')->get()->keyBy('period');
        $start = Carbon::parse($filters['start_date'])->startOfDay();
        $end = Carbon::parse($filters['end_date'])->startOfDay();
        $cursor = $monthly ? $start->copy()->startOfMonth() : $start;
        $last = $monthly ? $end->copy()->startOfMonth() : $end;
        $result = [];
        while ($cursor <= $last) {
            $period = $monthly ? $cursor->format('Y-m') : $cursor->toDateString();
            $result[] = ['period' => $period, $alias => (int) ($items->get($period)?->total ?? 0)];
            $cursor->add($monthly ? 'month' : 'day', 1);
        }

        return $result;
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $monthly = Carbon::parse($filters['start_date'])->diffInDays(Carbon::parse($filters['end_date'])) > 60;
        $ticketBase = $this->tickets($filters);
        $incidentBase = $this->incidents($filters);
        $createdTrend = $this->series(clone $ticketBase, 't.created_at', 'created', $filters, $monthly);
        $incidentTrend = $this->series(clone $incidentBase, 'i.created_at', 'total', $filters, $monthly);
        $closedPeriod = $this->withinPeriod($this->tickets($filters, false)->where('t.status', 'CLOSED')->whereNotNull('t.closed_at'), 't.closed_at', $filters);
        $closedTrend = $this->series(clone $closedPeriod, 't.closed_at', 'closed', $filters, $monthly);
        $closedByPeriod = collect($closedTrend)->keyBy('period');
        $trend = collect($createdTrend)->map(fn ($row) => [...$row, 'closed' => $closedByPeriod->get($row['period'])['closed'] ?? 0])->values();

        $summary = [
            'tickets_created' => (clone $ticketBase)->count(),
            'tickets_open_now' => $this->tickets($filters, false)->where('t.status', 'OPEN')->count(),
            'tickets_closed' => (clone $closedPeriod)->count(),
            'incidents' => (clone $incidentBase)->count(),
        ];
        $statusDistribution = (clone $ticketBase)->select('t.status')->selectRaw('COUNT(*) as total')->groupBy('t.status')->orderBy('t.status')->get();
        $topProblemTypes = (clone $incidentBase)->select('i.problem_type')->selectRaw('COUNT(*) as total')->groupBy('i.problem_type')->orderByDesc('total')->limit(10)->get();
        $topMachines = (clone $incidentBase)->select('i.machine_id', 'm.code', 'm.name', 'p.name as plant')->selectRaw('COUNT(i.id) as total')->groupBy('i.machine_id', 'm.code', 'm.name', 'p.name')->orderByDesc('total')->limit(10)->get();
        $incidentsByPlant = (clone $incidentBase)->select('i.plant_id', 'p.code', 'p.name')->selectRaw('COUNT(i.id) as total')->groupBy('i.plant_id', 'p.code', 'p.name')->orderByDesc('total')->get();
        $partBase = $this->withinPeriod(DB::table('maintenance_ticket_spare_parts as s')->join('maintenance_tickets as t', 't.id', '=', 's.maintenance_ticket_id')->leftJoin('machines as m', 'm.id', '=', 't.machine_id')->leftJoin('plants as p', 'p.id', '=', 't.plant_id')->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('t.plant_id', $id))->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('t.machine_id', $id)), 's.created_at', $filters);
        $machineBase = DB::table('machines as m')->leftJoin('plants as p', 'p.id', '=', 'm.plant_id')->where('m.is_active', true)->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('m.plant_id', $id))->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('m.id', $id))->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(m.code) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(m.name) LIKE ?', ['%'.strtolower($s).'%'])))->select('m.id', 'm.code', 'm.name', 'p.code as plant_code', 'p.name as plant_name')->selectSub(fn ($q) => $this->withinPeriod($q->from('incidents as ix')->whereColumn('ix.machine_id', 'm.id'), 'ix.created_at', $filters)->selectRaw('COUNT(*)'), 'incident_count')->orderBy('incident_count', ($filters['sort'] ?? 'incidents_desc') === 'incidents_asc' ? 'asc' : 'desc');
        $partsTop = (clone $partBase)->select('s.name', 's.material_code')->selectRaw('COUNT(*) as transactions, SUM(s.quantity) as quantity')->groupBy('s.name', 's.material_code')->orderByDesc('quantity')->limit(10)->get();

        $category = $filters['category'] ?? 'summary';
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $rows = null;
        if ($category === 'tickets') {
            $query = (clone $ticketBase)->select('t.id', 't.ticket_number', 't.created_at', 't.problem_type', 't.description', 't.status', 't.closed_at', 'p.name as plant', 'm.code as machine_code', 'm.name as machine', 'u.name as executor')->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(t.ticket_number) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(m.name) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(t.problem_type) LIKE ?', ['%'.strtolower($s).'%'])))->orderBy('t.created_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc');
            $rows = $query->paginate($perPage, ['*'], 'page', $page);
        } elseif ($category === 'incidents') {
            $query = (clone $incidentBase)->select('i.id', 'i.created_at', 'i.problem_type', 'i.description', 'i.status', 'i.plant_id', 'i.machine_id', 'p.name as plant', 'm.code as machine_code', 'm.name as machine', 't.ticket_number')->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(m.name) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(i.problem_type) LIKE ?', ['%'.strtolower($s).'%'])))->orderBy('i.created_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc');
            $rows = $query->paginate($perPage, ['*'], 'page', $page);
        } elseif ($category === 'machines') {
            $rows = (clone $machineBase)->paginate($perPage, ['*'], 'page', $page);
        } elseif ($category === 'spare-parts') {
            $query = (clone $partBase)->select('s.id', 's.name', 's.material_code', 's.quantity', 's.created_at', 's.remark', 't.ticket_number', 'm.code as machine_code', 'm.name as machine', 'p.name as plant')->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(s.name) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(s.material_code) LIKE ?', ['%'.strtolower($s).'%'])))->orderBy('s.created_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc');
            $rows = $query->paginate($perPage, ['*'], 'page', $page);
        }

        return response()->json([
            'filters' => ['start_date' => $filters['start_date'], 'end_date' => $filters['end_date'], 'plant_id' => $filters['plant_id'] ?? null, 'machine_id' => $filters['machine_id'] ?? null],
            'summary' => $summary,
            'trend' => $trend,
            'incident_trend' => $incidentTrend,
            'trend_granularity' => $monthly ? 'month' : 'day',
            'ticket_statuses' => $statusDistribution,
            'incident_types' => $topProblemTypes,
            'top_incident_machines' => $topMachines,
            'incidents_by_plant' => $incidentsByPlant,
            'machine_count_by_plant' => DB::table('machines as m')->leftJoin('plants as p', 'p.id', '=', 'm.plant_id')->select('m.plant_id', 'p.name as plant', 'p.code as plant_code')->selectRaw('COUNT(m.id) as total')->where('m.is_active', true)->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('m.plant_id', $id))->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('m.id', $id))->groupBy('m.plant_id', 'p.name', 'p.code')->orderBy('p.name')->get(),
            'machines' => $category === 'machines' ? $rows : null,
            'spare_parts' => ['transactions' => (clone $partBase)->count(), 'quantity' => (int) (clone $partBase)->sum('s.quantity'), 'top' => $partsTop],
            'rows' => $rows,
            'downtime' => ['available' => false, 'reason' => 'Sistem belum menyimpan waktu mulai dan waktu mesin kembali beroperasi. Durasi tiket tidak digunakan sebagai pengganti downtime.'],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $category = $filters['category'] ?? 'tickets';
        abort_unless(in_array($category, ['tickets', 'incidents', 'machines', 'spare-parts'], true), 422, 'Pilih kategori tabel yang akan diekspor.');
        $spareExport = $this->withinPeriod(DB::table('maintenance_ticket_spare_parts as s')->join('maintenance_tickets as t', 't.id', '=', 's.maintenance_ticket_id')->leftJoin('machines as m', 'm.id', '=', 't.machine_id')->leftJoin('plants as p', 'p.id', '=', 't.plant_id')->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('t.plant_id', $id))->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('t.machine_id', $id))->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(s.name) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(s.material_code) LIKE ?', ['%'.strtolower($s).'%']))), 's.created_at', $filters);
        $machineExport = DB::table('machines as m')->leftJoin('plants as p', 'p.id', '=', 'm.plant_id')->where('m.is_active', true)->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('m.plant_id', $id))->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('m.id', $id))->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(m.code) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(m.name) LIKE ?', ['%'.strtolower($s).'%'])))->select('m.code as Kode mesin', 'm.name as Mesin', 'p.name as Plant')->selectSub(fn ($q) => $this->withinPeriod($q->from('incidents as i')->whereColumn('i.machine_id', 'm.id'), 'i.created_at', $filters)->selectRaw('COUNT(*)'), 'Jumlah insiden')->orderBy('Jumlah insiden', ($filters['sort'] ?? 'incidents_desc') === 'incidents_asc' ? 'asc' : 'desc');
        $query = match ($category) {
            'tickets' => $this->tickets($filters)->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(t.ticket_number) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(m.name) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(t.problem_type) LIKE ?', ['%'.strtolower($s).'%'])))->select('t.ticket_number as Nomor tiket', 't.created_at as Tanggal', 'p.name as Plant', 'm.name as Mesin', 't.problem_type as Jenis pekerjaan', 't.status as Status', 'u.name as Penanggung jawab', 't.closed_at as Waktu selesai')->orderBy('t.created_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc'),
            'incidents' => $this->incidents($filters)->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(m.name) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(i.problem_type) LIKE ?', ['%'.strtolower($s).'%'])))->select('i.created_at as Tanggal', 'p.name as Plant', 'm.name as Mesin', 'i.problem_type as Jenis masalah', 'i.description as Deskripsi', 'i.status as Status', 't.ticket_number as Nomor tiket')->orderBy('i.created_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc'),
            'spare-parts' => $spareExport->select('s.created_at as Tanggal', 'p.name as Plant', 'm.name as Mesin', 't.ticket_number as Nomor tiket', 's.name as Spare part', 's.material_code as Kode material', 's.quantity as Kuantitas')->orderBy('s.created_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc'),
            default => $machineExport,
        };

        return response()->streamDownload(function () use ($query): void {
            abort_unless(class_exists(ZipArchive::class), 500, 'Ekspor Excel membutuhkan ekstensi PHP zip.');
            $path = tempnam(sys_get_temp_dir(), 'maintenance-report-');
            abort_if($path === false, 500, 'Tidak dapat menyiapkan berkas Excel.');

            try {
                $rows = [];
                foreach ($query->cursor() as $row) {
                    $rows[] = (array) $row;
                }
                $headers = $rows === [] ? ['Tidak ada data'] : array_keys($rows[0]);
                $sheetRows = [$headers];
                foreach ($rows as $row) {
                    $sheetRows[] = array_values($row);
                }

                $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
                foreach ($sheetRows as $rowIndex => $cells) {
                    $excelRow = $rowIndex + 1;
                    $sheet .= '<row r="'.$excelRow.'">';
                    foreach ($cells as $columnIndex => $value) {
                        $cell = '';
                        for ($n = $columnIndex + 1; $n > 0; $n = intdiv($n - 1, 26)) {
                            $cell = chr(65 + (($n - 1) % 26)).$cell;
                        }
                        $reference = $cell.$excelRow;
                        if (is_int($value) || is_float($value)) {
                            $sheet .= '<c r="'.$reference.'"><v>'.$value.'</v></c>';
                        } else {
                            $text = htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                            $sheet .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>';
                        }
                    }
                    $sheet .= '</row>';
                }
                $sheet .= '</sheetData></worksheet>';

                $zip = new ZipArchive;
                abort_unless($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500, 'Tidak dapat membuat berkas Excel.');
                $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
                $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
                $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Laporan" sheetId="1" r:id="rId1"/></sheets></workbook>');
                $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
                $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
                $zip->close();
                readfile($path);
            } finally {
                @unlink($path);
            }
        }, "laporan-{$category}-{$filters['start_date']}-{$filters['end_date']}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
