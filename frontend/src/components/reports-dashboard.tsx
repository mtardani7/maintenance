'use client';

import { Download, RotateCcw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { getPlantOptions, type PlantOption } from '@/lib/maintenance-api';
import { exportReports, getReportMetrics, operationsApiMessage } from '@/lib/operations-api';
import type { OperationsFilters, ReportMetrics } from '@/lib/operations-types';
import { CardSkeleton, EmptyState, ErrorState, ListSkeleton } from './ui';

const cards: { key: keyof ReportMetrics; label: string }[] = [
  { key: 'mttr', label: 'Rata-rata waktu perbaikan' }, { key: 'mtbf', label: 'Rata-rata waktu antar gangguan' },
  { key: 'totalDowntime', label: 'Total waktu henti' }, { key: 'responseTime', label: 'Waktu respons' },
  { key: 'slaCompliance', label: 'Kepatuhan SLA' }, { key: 'ticketVolume', label: 'Jumlah tiket' },
  { key: 'repeatFailures', label: 'Gangguan berulang' },
];
function metricValue(value: ReportMetrics[keyof ReportMetrics]) { return Array.isArray(value) ? '—' : value ?? '—'; }

export function ReportsDashboard() {
  const [filters, setFilters] = useState<OperationsFilters>({});
  const [plants, setPlants] = useState<PlantOption[]>([]);
  const [metrics, setMetrics] = useState<ReportMetrics | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [exporting, setExporting] = useState(false);
  const [retryKey, setRetryKey] = useState(0);
  useEffect(() => { getPlantOptions().then(setPlants).catch(() => setPlants([])); }, []);
  useEffect(() => {
    let active = true;
    setLoading(true); setError('');
    getReportMetrics(filters).then((data) => { if (active) setMetrics(data); }).catch((reason) => { if (active) setError(operationsApiMessage(reason)); }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [filters, retryKey]);
  async function exportReport() {
    setExporting(true); setError('');
    try { const blob = await exportReports(filters); const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = 'laporan-pemeliharaan.csv'; link.click(); URL.revokeObjectURL(url); }
    catch (reason) { setError(operationsApiMessage(reason)); }
    finally { setExporting(false); }
  }
  function resetFilters() { setFilters({}); }

  return <div className="reports-dashboard">
    <div className="report-controls" aria-label="Filter laporan">
      <label>Plant<select value={filters.plant ?? ''} onChange={(event) => setFilters({ ...filters, plant: event.target.value || undefined })}><option value="">Semua Plant</option>{plants.map((plant) => <option key={plant.id} value={plant.id}>{plant.code} — {plant.name}</option>)}</select></label>
      <label>Tanggal<input type="date" value={filters.date ?? ''} onChange={(event) => setFilters({ ...filters, date: event.target.value || undefined })} /></label>
      <button className="secondary-action" type="button" onClick={resetFilters}><RotateCcw aria-hidden="true" />Reset filter</button>
      <button className="secondary-action" type="button" onClick={() => void exportReport()} disabled={exporting}><Download aria-hidden="true" />{exporting ? 'Menyiapkan...' : 'Ekspor laporan'}</button>
    </div>
    {error ? <ErrorState title="Laporan belum tersedia" description="Periksa koneksi lalu coba muat ulang." onRetry={() => setRetryKey((value) => value + 1)} /> : loading ? <>
      <div className="report-metric-grid">{cards.map((card) => <CardSkeleton key={card.key} className="metric-card" />)}</div>
      <div className="report-tables">{["Pemeliharaan per Plant", "Mesin dengan gangguan terbanyak", "Jenis gangguan terbanyak"].map((title) => <section className="work-panel report-table" key={title}><div className="work-panel__header"><h3>{title}</h3></div><ListSkeleton rows={4} className="report-skeleton-list" /></section>)}</div>
    </> : metrics && Object.keys(metrics).length ? <>
      <div className="report-metric-grid">{cards.map((card) => <article className="metric-card" key={card.key}><span className="metric-card__label">{card.label}</span><strong className="metric-card__value">{metricValue(metrics[card.key])}</strong></article>)}</div>
      <div className="report-tables"><ReportTable title="Pemeliharaan per Plant" rows={metrics.maintenanceByPlant?.map((item) => [item.plant, String(item.total)]) ?? []} /><ReportTable title="Mesin dengan gangguan terbanyak" rows={metrics.topProblematicMachines?.map((item) => [item.machine, String(item.total)]) ?? []} /><ReportTable title="Jenis gangguan terbanyak" rows={metrics.topFailureTypes?.map((item) => [item.type, String(item.total)]) ?? []} /></div>
    </> : <EmptyState title="Belum ada data laporan" description="Data akan tampil setelah aktivitas pemeliharaan tersedia." />}
  </div>;
}

function ReportTable({ title, rows }: { title: string; rows: string[][] }) {
  return <section className="work-panel report-table"><div className="work-panel__header"><h3>{title}</h3></div>{rows.length ? rows.map((row) => <div className="report-row" key={row[0]}><span>{row[0]}</span><strong>{row[1]}</strong></div>) : <EmptyState title="Belum ada data" description="Belum ada catatan untuk ditampilkan." />}</section>;
}
