'use client';

import Link from 'next/link';
import { BarChart3, Download, RotateCcw, Search } from 'lucide-react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { getMachines, getPlantOptions, type PlantOption } from '@/lib/maintenance-api';
import type { Machine } from '@/lib/maintenance-types';
import { exportReports, getReportMetrics, operationsApiMessage } from '@/lib/operations-api';
import type { MaintenanceReport, ReportCategory, ReportFilters, ReportRowPage } from '@/lib/operations-types';
import { DataPagination, EmptyState, ErrorState, MaintenanceTable } from './ui';

const categories: { id: ReportCategory; label: string }[] = [
  { id: 'summary', label: 'Ringkasan' }, { id: 'tickets', label: 'Tiket Maintenance' }, { id: 'incidents', label: 'Insiden' },
  { id: 'machines', label: 'Mesin & Plant' }, { id: 'spare-parts', label: 'Spare Part' }, { id: 'downtime', label: 'Downtime' },
];
const today = () => new Date().toLocaleDateString('sv-SE', { timeZone: 'Asia/Jakarta' });
const daysBefore = (days: number) => { const date = new Date(`${today()}T00:00:00+07:00`); date.setDate(date.getDate() - days); return date.toLocaleDateString('sv-SE', { timeZone: 'Asia/Jakarta' }); };
const number = new Intl.NumberFormat('id-ID');
const dateFormatter = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'Asia/Jakarta' });
function formatDate(value: unknown) { if (typeof value !== 'string') return '—'; const date = new Date(value); return Number.isNaN(date.getTime()) ? '—' : dateFormatter.format(date); }
function cell(value: unknown) { return value === null || value === undefined || value === '' ? '—' : String(value); }

export function ReportsDashboard() {
  const [plants, setPlants] = useState<PlantOption[]>([]);
  const [machines, setMachines] = useState<Machine[]>([]);
  const [category, setCategory] = useState<ReportCategory>('summary');
  const [draft, setDraft] = useState({ start_date: daysBefore(29), end_date: today(), plant_id: '', machine_id: '', search: '', sort: 'newest' as ReportFilters['sort'] });
  const [applied, setApplied] = useState(draft);
  const [report, setReport] = useState<MaintenanceReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [exporting, setExporting] = useState(false);
  const [page, setPage] = useState(1);
  const [retryKey, setRetryKey] = useState(0);

  useEffect(() => { getPlantOptions().then(setPlants).catch(() => setPlants([])); }, []);
  useEffect(() => {
    let active = true;
    if (!draft.plant_id) { setMachines([]); return () => { active = false; }; }
    getMachines({ plant: draft.plant_id, per_page: 100 }).then((items) => { if (active) setMachines(items.filter((machine) => machine.is_active)); }).catch(() => { if (active) setMachines([]); });
    return () => { active = false; };
  }, [draft.plant_id]);
  useEffect(() => {
    let active = true;
    const filters: ReportFilters = { ...applied, plant_id: applied.plant_id || undefined, machine_id: applied.machine_id || undefined, search: applied.search || undefined, category, page, per_page: 20 };
    setLoading(true); setError('');
    getReportMetrics(filters).then((data) => { if (active) setReport(data); }).catch((reason) => { if (active) setError(operationsApiMessage(reason)); }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [applied, category, page, retryKey]);

  const rowPage = (category === 'machines' ? report?.machines : report?.rows) as ReportRowPage | null | undefined;
  const exportable = category !== 'summary' && category !== 'downtime';
  const columns = useMemo(() => tableColumns(category), [category]);
  function applyFilters(event: FormEvent<HTMLFormElement>) { event.preventDefault(); setPage(1); setApplied({ ...draft }); }
  function resetFilters() { const next = { start_date: daysBefore(29), end_date: today(), plant_id: '', machine_id: '', search: '', sort: 'newest' as const }; setDraft(next); setApplied(next); setPage(1); }
  async function exportReport() {
    setExporting(true);
    try {
      const filters: ReportFilters = { ...applied, plant_id: applied.plant_id || undefined, machine_id: applied.machine_id || undefined, search: applied.search || undefined, category, per_page: 100 };
      const blob = await exportReports(filters); const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = `laporan-${category}-${applied.start_date}-${applied.end_date}.csv`; link.click(); URL.revokeObjectURL(url);
    } catch (reason) { setError(operationsApiMessage(reason)); }
    finally { setExporting(false); }
  }

  return <div className="reports-dashboard">
    <div className="reports-dashboard__intro"><div><span className="reports-dashboard__icon"><BarChart3 aria-hidden="true" /></span><div><h2>Analisis historis maintenance</h2><p>Telusuri aktivitas berdasarkan periode, plant, dan mesin.</p></div></div><span className="reports-dashboard__period">Zona waktu WIB</span></div>
    <form className="report-controls" aria-label="Filter laporan" onSubmit={applyFilters}>
      <label>Tanggal mulai<input type="date" required value={draft.start_date} max={draft.end_date} onChange={(event) => setDraft({ ...draft, start_date: event.target.value })} /></label>
      <label>Tanggal akhir<input type="date" required value={draft.end_date} min={draft.start_date} onChange={(event) => setDraft({ ...draft, end_date: event.target.value })} /></label>
      <label>Plant<select value={draft.plant_id} onChange={(event) => setDraft({ ...draft, plant_id: event.target.value, machine_id: '' })}><option value="">Semua Plant</option>{plants.map((plant) => <option key={plant.id} value={plant.id}>{plant.code} — {plant.name}</option>)}</select></label>
      <label>Mesin<select value={draft.machine_id} disabled={!draft.plant_id} onChange={(event) => setDraft({ ...draft, machine_id: event.target.value })}><option value="">Semua Mesin</option>{machines.map((machine) => <option key={machine.id} value={machine.id}>{machine.code} — {machine.name}</option>)}</select></label>
      <label className="report-search"><Search aria-hidden="true" /><input type="search" value={draft.search} placeholder="Cari pada tabel aktif" aria-label="Cari pada tabel aktif" onChange={(event) => setDraft({ ...draft, search: event.target.value })} /></label>
      {(category === 'machines' || category === 'tickets' || category === 'incidents' || category === 'spare-parts') && <label>Urutkan<select value={category === 'machines' ? (draft.sort === 'incidents_asc' ? 'incidents_asc' : 'incidents_desc') : (draft.sort === 'oldest' ? 'oldest' : 'newest')} onChange={(event) => setDraft({ ...draft, sort: event.target.value as ReportFilters['sort'] })}>{category === 'machines' ? <><option value="incidents_desc">Insiden terbanyak</option><option value="incidents_asc">Insiden paling sedikit</option></> : <><option value="newest">Terbaru</option><option value="oldest">Terlama</option></>}</select></label>}
      <div className="report-controls__actions"><button className="secondary-action" type="button" onClick={resetFilters}><RotateCcw aria-hidden="true" />Reset</button><button className="secondary-action report-apply-action" type="submit">Terapkan Filter</button><button className="secondary-action report-export-action" type="button" onClick={() => void exportReport()} disabled={!exportable || exporting}><Download aria-hidden="true" />{exporting ? 'Menyiapkan…' : 'Ekspor CSV'}</button></div>
    </form>

    <nav className="report-tabs" aria-label="Kategori laporan" role="tablist">{categories.map((item) => <button key={item.id} type="button" role="tab" aria-selected={category === item.id} className={category === item.id ? 'report-tab report-tab--active' : 'report-tab'} onClick={() => { setCategory(item.id); setPage(1); }}>{item.label}</button>)}</nav>

    {error ? <ErrorState title="Laporan tidak dapat dimuat" description={error} onRetry={() => setRetryKey((value) => value + 1)} /> : loading ? <section className="report-loading" role="status">Memuat laporan…</section> : report ? <div className="report-content" role="tabpanel" aria-label={categories.find((item) => item.id === category)?.label}>
      {category === 'summary' && <Summary report={report} />}
      {category === 'tickets' && <><SectionTitle title="Tiket Maintenance" note={`${number.format(rowPage?.total ?? 0)} tiket dibuat pada periode terpilih`} /><Distribution rows={report.ticket_statuses.map((item) => ({ label: item.status, value: item.total }))} /></>}
      {category === 'incidents' && <IncidentInsights report={report} />}
      {category === 'machines' && <><SectionTitle title="Mesin & Plant" note="Jumlah insiden dihitung dari insiden pada periode terpilih; downtime belum tersedia." /><div className="report-insight-grid"><article className="report-insight-card"><h3>Jumlah mesin aktif per plant</h3><Distribution rows={report.machine_count_by_plant.map((item) => ({ label: `${item.plant_code ?? ''} ${item.plant ?? 'Plant tidak diketahui'}`.trim(), value: item.total }))} /></article><article className="report-insight-card"><h3>Insiden per plant</h3><Distribution rows={report.incidents_by_plant.map((item) => ({ label: item.name ?? 'Plant tidak diketahui', value: item.total }))} /></article></div></>}
      {category === 'spare-parts' && <><SectionTitle title="Penggunaan Spare Part" note={`${number.format(report.spare_parts.transactions)} transaksi · ${number.format(report.spare_parts.quantity)} unit digunakan`} /><Distribution rows={report.spare_parts.top.map((item) => ({ label: `${item.name} · ${item.material_code}`, value: item.quantity }))} /></>}
      {category === 'downtime' && <DowntimeNotice reason={report.downtime.reason} />}
      {category !== 'summary' && category !== 'downtime' && <ReportTable category={category} columns={columns} rows={rowPage?.data ?? []} page={rowPage ?? undefined} onPageChange={setPage} />}
    </div> : <EmptyState title="Belum ada data laporan" description="Tidak ada data untuk filter yang dipilih." />}
  </div>;
}

function Summary({ report }: { report: MaintenanceReport }) {
  return <><SectionTitle title="Ringkasan" note={`Periode ${formatDate(report.filters.start_date)} – ${formatDate(report.filters.end_date)}`} />
    <div className="report-metric-grid" aria-label="Indikator ringkasan">
      <Metric label="Tiket dibuat dalam periode" value={report.summary.tickets_created} />
      <Metric label="Tiket OPEN saat ini" value={report.summary.tickets_open_now} hint="Semua tiket aktif pada plant/mesin terpilih" />
      <Metric label="Tiket diselesaikan dalam periode" value={report.summary.tickets_closed} />
      <Metric label="Insiden dalam periode" value={report.summary.incidents} />
    </div>
    <TrendChart report={report} />
    <div className="report-insight-grid"><article className="report-insight-card"><h3>Jenis insiden terbanyak</h3><Distribution rows={report.incident_types.slice(0, 5).map((item) => ({ label: item.problem_type, value: item.total }))} /></article><article className="report-insight-card"><h3>Mesin dengan insiden terbanyak</h3><Distribution rows={report.top_incident_machines.slice(0, 5).map((item) => ({ label: `${item.code} · ${item.name}`, value: item.total }))} /></article></div>
  </>;
}
function Metric({ label, value, hint }: { label: string; value: number; hint?: string }) { return <article className="metric-card"><span className="metric-card__label">{label}</span><strong className="metric-card__value">{number.format(value)}</strong>{hint && <span className="metric-card__status">{hint}</span>}</article>; }
function SectionTitle({ title, note }: { title: string; note: string }) { return <header className="report-section-title"><div><h2>{title}</h2><p>{note}</p></div></header>; }

function TrendChart({ report }: { report: MaintenanceReport }) {
  const max = Math.max(1, ...report.trend.flatMap((item) => [item.created, item.closed]));
  const hasData = report.trend.some((item) => item.created > 0 || item.closed > 0);
  return <section className="report-chart-card"><header><div><h3>Tiket dibuat dan diselesaikan</h3><p>Jumlah tiket per {report.trend_granularity === 'month' ? 'bulan' : 'hari'}</p></div><div className="report-chart-legend"><span><i className="created" />Dibuat</span><span><i className="closed" />Diselesaikan</span></div></header>
    {hasData ? <div className="report-chart-scroll"><div className="report-chart" role="img" aria-label="Grafik jumlah tiket dibuat dan diselesaikan sepanjang periode" style={{ minWidth: `${Math.max(560, report.trend.length * 24)}px` }}>{report.trend.map((item) => <div className="report-chart__group" key={item.period} title={`${item.period}: ${item.created} dibuat, ${item.closed} diselesaikan`}><div className="report-chart__bars"><i className="created" style={{ height: `${item.created / max * 100}%` }} /><i className="closed" style={{ height: `${item.closed / max * 100}%` }} /></div><small>{report.trend_granularity === 'month' ? item.period : item.period.slice(8)}</small></div>)}</div></div> : <EmptyState title="Belum ada tren tiket" description="Data tren muncul saat ada tiket pada periode terpilih." />}
  </section>;
}
function Distribution({ rows }: { rows: { label: string; value: number }[] }) { const max = Math.max(1, ...rows.map((row) => row.value)); return rows.length ? <ul className="report-distribution">{rows.map((row) => <li key={row.label}><div><span>{row.label}</span><strong>{number.format(row.value)}</strong></div><i><b style={{ width: `${row.value / max * 100}%` }} /></i></li>)}</ul> : <p className="report-no-breakdown">Belum ada data untuk ditampilkan.</p>; }
function IncidentInsights({ report }: { report: MaintenanceReport }) { const max = Math.max(1, ...report.incident_trend.map((item) => item.total)); const hasData = report.incident_trend.some((item) => item.total > 0); return <><SectionTitle title="Analisis Insiden" note={`${number.format(report.summary.incidents)} insiden pada periode terpilih`} /><section className="report-chart-card"><header><div><h3>Tren insiden</h3><p>Jumlah insiden per {report.trend_granularity === 'month' ? 'bulan' : 'hari'}</p></div></header>{hasData ? <div className="report-chart-scroll"><div className="report-chart report-chart--single" role="img" aria-label="Grafik jumlah insiden sepanjang periode" style={{ minWidth: `${Math.max(560, report.incident_trend.length * 24)}px` }}>{report.incident_trend.map((item) => <div className="report-chart__group" key={item.period} title={`${item.period}: ${item.total} insiden`}><div className="report-chart__bars"><i className="created" style={{ height: `${item.total / max * 100}%` }} /></div><small>{report.trend_granularity === 'month' ? item.period : item.period.slice(8)}</small></div>)}</div></div> : <EmptyState title="Belum ada tren insiden" description="Data tren muncul saat ada insiden pada periode terpilih." />}</section><div className="report-insight-grid"><article className="report-insight-card"><h3>Jenis masalah terbanyak</h3><Distribution rows={report.incident_types.map((item) => ({ label: item.problem_type, value: item.total }))} /></article><article className="report-insight-card"><h3>Insiden per plant</h3><Distribution rows={report.incidents_by_plant.map((item) => ({ label: item.name ?? 'Plant tidak diketahui', value: item.total }))} /></article><article className="report-insight-card report-insight-card--wide"><h3>Mesin dengan insiden terbanyak</h3><Distribution rows={report.top_incident_machines.map((item) => ({ label: `${item.code} · ${item.name}`, value: item.total }))} /></article></div></>; }
function DowntimeNotice({ reason }: { reason: string }) { return <section className="report-downtime-notice"><span>Data downtime belum tersedia</span><h2>Waktu berhenti dan waktu beroperasi kembali belum dicatat</h2><p>{reason} Untuk menghasilkan laporan downtime yang akurat, aplikasi perlu menyimpan waktu mulai dan selesai kejadian, mesin, penyebab, serta relasi tiket.</p></section>; }

function tableColumns(category: ReportCategory) {
  if (category === 'tickets') return [{ key: 'ticket_number', label: 'Nomor tiket', ticket: true }, { key: 'created_at', label: 'Tanggal', date: true }, { key: 'plant', label: 'Plant' }, { key: 'machine', label: 'Mesin' }, { key: 'problem_type', label: 'Jenis pekerjaan' }, { key: 'status', label: 'Status' }, { key: 'executor', label: 'Penanggung jawab' }, { key: 'closed_at', label: 'Waktu selesai', date: true }];
  if (category === 'incidents') return [{ key: 'created_at', label: 'Tanggal', date: true }, { key: 'plant', label: 'Plant' }, { key: 'machine', label: 'Mesin' }, { key: 'problem_type', label: 'Jenis masalah' }, { key: 'description', label: 'Deskripsi' }, { key: 'status', label: 'Status' }, { key: 'ticket_number', label: 'Tiket terkait', ticket: true }];
  if (category === 'machines') return [{ key: 'code', label: 'Kode mesin' }, { key: 'name', label: 'Mesin' }, { key: 'plant_name', label: 'Plant' }, { key: 'incident_count', label: 'Insiden' }];
  return [{ key: 'created_at', label: 'Tanggal', date: true }, { key: 'plant', label: 'Plant' }, { key: 'machine', label: 'Mesin' }, { key: 'ticket_number', label: 'Tiket', ticket: true }, { key: 'name', label: 'Spare part' }, { key: 'material_code', label: 'Kode material' }, { key: 'quantity', label: 'Kuantitas' }];
}
function ReportTable({ category, columns, rows, page, onPageChange }: { category: ReportCategory; columns: { key: string; label: string; date?: boolean; ticket?: boolean }[]; rows: Record<string, unknown>[]; page?: ReportRowPage; onPageChange: (page: number) => void }) {
  if (!rows.length) return <EmptyState title="Belum ada data" description="Tidak ada baris yang sesuai dengan filter ini." />;
  return <><MaintenanceTable className="report-data-table" label={`Laporan ${category}`}><thead><tr>{columns.map((column) => <th key={column.key}>{column.label}</th>)}</tr></thead><tbody>{rows.map((row, index) => <tr key={cell(row.id ?? row.ticket_number ?? `${category}-${index}`)}>{columns.map((column) => <td key={column.key}>{column.ticket && row[column.key] ? <Link className="report-ticket-link" href={`/tickets/${encodeURIComponent(String(row[column.key]))}`}>{cell(row[column.key])}</Link> : column.date ? formatDate(row[column.key]) : cell(row[column.key])}</td>)}</tr>)}</tbody></MaintenanceTable>
    {page && <DataPagination currentPage={page.current_page} totalPages={page.last_page} summary={`Menampilkan ${number.format((page.current_page - 1) * page.per_page + 1)}–${number.format(Math.min(page.current_page * page.per_page, page.total))} dari ${number.format(page.total)}`} mobileSummary={`${number.format(page.total)} data`} onPageChange={onPageChange} />}</>;
}
