'use client';

import { Activity, AlertTriangle, Clock3, RefreshCw, Wrench } from 'lucide-react';
import ReactECharts from 'echarts-for-react';
import type { EChartsOption } from 'echarts';
import { useEffect, useState } from 'react';
import { getMachinePage, getPlantOptions, getQaDashboard, type QaDashboard } from '@/lib/maintenance-api';
import type { Machine, MachinePage } from '@/lib/maintenance-types';
import { CardSkeleton, ErrorState, Skeleton } from './ui';

type DashboardFilters = { period: 'daily' | 'weekly' | 'monthly'; date: string; plant: string; line: string; machine: string };
type ChartPoint = { label: string; value: number };

const formatNumber = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });
const today = () => new Date().toISOString().slice(0, 10);
const periodLabels: Record<DashboardFilters['period'], string> = { daily: 'Harian', weekly: 'Mingguan', monthly: 'Bulanan' };

function ChartCard({ title, children }: { title: string; children: React.ReactNode }) {
  return <section className="maintenance-chart-card"><div className="maintenance-chart-heading"><h3>{title}</h3></div>{children}</section>;
}

function EmptyChart({ message = 'Tidak ada data pemeliharaan untuk filter yang dipilih.' }: { message?: string }) {
  return <div className="maintenance-chart-empty">{message}</div>;
}

function BarChart({ rows, color = 'red' }: { rows: ChartPoint[]; color?: 'red' | 'slate' }) {
  if (!rows.length) return <EmptyChart />;
  const option: EChartsOption = { tooltip: { trigger: 'axis', valueFormatter: (value) => formatNumber.format(Number(value)) }, grid: { left: 128, right: 28, top: 18, bottom: 34, containLabel: true }, xAxis: { type: 'value', min: 0, ...chartAxis, axisLabel: { color: '#64748b' } }, yAxis: { type: 'category', data: [...rows].reverse().map((row) => row.label), axisLabel: { color: '#64748b', width: 112, overflow: 'truncate' } }, series: [{ type: 'bar', data: [...rows].reverse().map((row) => row.value), barMaxWidth: 28, itemStyle: { color: color === 'red' ? '#ef4444' : '#64748b' }, label: { show: true, position: 'right', color: '#475569', fontSize: 11 } }] };
  return <ReactECharts option={option} style={{ height: 320, width: '100%' }} opts={{ renderer: 'canvas' }} />;
}

function LineChart({ rows, color = 'red' }: { rows: ChartPoint[]; color?: 'red' | 'slate' }) {
  if (!rows.length) return <EmptyChart />;
  const option: EChartsOption = { tooltip: { trigger: 'axis', valueFormatter: (value) => formatNumber.format(Number(value)) }, grid: { left: 52, right: 20, top: 24, bottom: 40, containLabel: true }, xAxis: { type: 'category', data: rows.map((row) => row.label), ...chartAxis, axisLabel: { color: '#64748b', hideOverlap: true } }, yAxis: { type: 'value', min: 0, ...chartAxis, axisLabel: { color: '#64748b' } }, series: [{ type: 'line', smooth: true, data: rows.map((row) => row.value), areaStyle: { opacity: 0.12 }, itemStyle: { color: color === 'red' ? '#ef4444' : '#64748b' } }] };
  return <ReactECharts option={option} style={{ height: 320, width: '100%' }} opts={{ renderer: 'canvas' }} />;
}

const chartAxis = { axisLine: { lineStyle: { color: '#cbd5e1' } }, splitLine: { lineStyle: { color: '#e2e8f0' } } };

export function OperationsDashboard() {
  const [machines, setMachines] = useState<Machine[]>([]);
  const [qaDashboard, setQaDashboard] = useState<QaDashboard | null>(null);
  const [plants, setPlants] = useState<{ id: number; code: string; name: string }[]>([]);
  const [filters, setFilters] = useState<DashboardFilters>({ period: 'monthly', date: today(), plant: '', line: '', machine: '' });
  const [refreshKey, setRefreshKey] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;
    setLoading(true); setError('');
    Promise.allSettled([
      getMachinePage({ page: 1, per_page: 100 }),
      getPlantOptions(),
      getQaDashboard({ date: filters.date, period: filters.period, plant_id: filters.plant || undefined, line_id: filters.line || undefined, machine_id: filters.machine || undefined }),
    ]).then(([machineResult, plantResult, qaResult]) => {
      if (!active) return;
      if (machineResult.status === 'fulfilled') setMachines(machineResult.value.data);
      if (plantResult.status === 'fulfilled') setPlants(plantResult.value);
      if (qaResult.status === 'fulfilled') setQaDashboard({ ...qaResult.value, summary: qaResult.value.summary ?? { production_pcs: 0, defect_qty: 0, defect_rate: 0, yield: 0 } });
      if ([machineResult, plantResult, qaResult].every((result) => result.status === 'rejected')) setError('Data dasbor tidak dapat dimuat. Periksa koneksi lalu coba lagi.');
    }).catch(() => { if (active) setError('Data dasbor tidak dapat dimuat. Periksa koneksi lalu coba lagi.'); }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [filters, refreshKey]);

  const lineOptions = Array.from(new Map(machines.filter((machine) => !filters.plant || String(machine.plant_id) === filters.plant).filter((machine) => machine.line_id).map((machine) => [String(machine.line_id), machine.line])).entries());
  const machineOptions = machines.filter((machine) => !filters.plant || String(machine.plant_id) === filters.plant).filter((machine) => !filters.line || String(machine.line_id) === filters.line);
  const summary = qaDashboard?.summary ?? { production_pcs: 0, defect_qty: 0, defect_rate: 0, yield: 0 };
  const defectCategories = Array.isArray(qaDashboard?.defect_categories) ? qaDashboard.defect_categories : [];
  const productionTrend = Array.isArray(qaDashboard?.production_trend) ? qaDashboard.production_trend : [];
  const defectTrend = Array.isArray(qaDashboard?.defect_trend) ? qaDashboard.defect_trend : [];
  const pareto = Array.isArray(qaDashboard?.pareto) ? qaDashboard.pareto : [];
  const topMachines = Array.isArray(qaDashboard?.top_machines) ? qaDashboard.top_machines : [];
  const trendRows = productionTrend.map((row) => ({ label: row.date, value: row.production_pcs }));
  const statusRows = defectTrend.map((row) => ({ label: row.date, value: row.defect_qty }));
  const breakdownRows = pareto.map((row) => ({ label: row.name, value: row.quantity }));
  const machineRows = topMachines.map((row) => ({ label: row.name, value: row.output_pcs ?? row.value }));
  const production = summary.production_pcs;
  const defects = summary.defect_qty;

  return <div className="operations-dashboard maintenance-dashboard"><section className="maintenance-dashboard-header"><div><div className="maintenance-dashboard-label"><Activity aria-hidden="true" /> PEMELIHARAAN</div><h1>Ringkasan Operasional</h1><p>Pantau hasil produksi dan temuan mutu untuk mendukung keputusan pemeliharaan.</p></div><button className="maintenance-refresh" type="button" onClick={() => setRefreshKey((value) => value + 1)} disabled={loading}><RefreshCw aria-hidden="true" />{loading ? 'Memuat…' : 'Muat ulang'}</button></section><section className="maintenance-filters"><div className="maintenance-filter-heading"><div><strong>Filter ringkasan</strong><span>Pilih cakupan data yang ingin dilihat.</span></div><button type="button" disabled={loading} onClick={() => setFilters({ period: 'monthly', date: today(), plant: '', line: '', machine: '' })}>Reset filter</button></div><label>Periode<select disabled={loading} value={filters.period} onChange={(event) => setFilters({ ...filters, period: event.target.value as DashboardFilters['period'] })}><option value="daily">Harian</option><option value="weekly">Mingguan</option><option value="monthly">Bulanan</option></select></label><label>Tanggal<input disabled={loading} type="date" value={filters.date} onChange={(event) => setFilters({ ...filters, date: event.target.value })} /></label><label>Plant<select disabled={loading} value={filters.plant} onChange={(event) => setFilters({ ...filters, plant: event.target.value, line: '', machine: '' })}><option value="">Semua Plant</option>{plants.map((plant) => <option key={plant.id} value={String(plant.id)}>{plant.code} — {plant.name}</option>)}</select></label><label>Line<select disabled={loading} value={filters.line} onChange={(event) => setFilters({ ...filters, line: event.target.value, machine: '' })}><option value="">Semua line</option>{lineOptions.map(([id, name]) => <option key={id} value={id}>{name}</option>)}</select></label><label>Mesin<select disabled={loading} value={filters.machine} onChange={(event) => setFilters({ ...filters, machine: event.target.value })}><option value="">Semua mesin</option>{machineOptions.map((machine) => <option key={machine.id} value={machine.id}>{machine.code} — {machine.name}</option>)}</select></label></section>{error ? <ErrorState title="Dasbor pemeliharaan tidak tersedia" description="Data belum dapat dimuat. Periksa koneksi lalu coba lagi." onRetry={() => setRefreshKey((value) => value + 1)} /> : <><section><div className="maintenance-section-title"><h2>Ringkasan {periodLabels[filters.period].toLowerCase()}</h2><span>{filters.date}</span></div><div className="maintenance-kpis">{loading ? [0, 1, 2, 3].map((item) => <CardSkeleton className="maintenance-kpi" key={item} />) : <><KpiCard label="Jumlah produksi" value={formatNumber.format(production)} icon={Wrench} /><KpiCard label="Jumlah cacat" value={formatNumber.format(defects)} icon={AlertTriangle} /><KpiCard label="Rasio cacat" value={`${qaDashboard?.summary.defect_rate ?? 0}%`} icon={Clock3} /><KpiCard label="Hasil baik" value={`${qaDashboard?.summary.yield ?? 0}%`} icon={AlertTriangle} /></>}</div></section><section><div className="maintenance-section-title"><h2>Tren dan prioritas mutu</h2></div><div className="maintenance-grid"><ChartCard title="Tren produksi">{loading ? <Skeleton className="maintenance-chart-skeleton" /> : <LineChart rows={trendRows} />}</ChartCard><ChartCard title="Tren cacat">{loading ? <Skeleton className="maintenance-chart-skeleton" /> : <LineChart rows={statusRows} color="slate" />}</ChartCard><ChartCard title="Jenis cacat terbanyak">{loading ? <Skeleton className="maintenance-chart-skeleton" /> : <BarChart rows={breakdownRows} />}</ChartCard><ChartCard title="Produksi per mesin">{loading ? <Skeleton className="maintenance-chart-skeleton" /> : <BarChart rows={machineRows} color="slate" />}</ChartCard></div></section></>}</div>;
}

function KpiCard({ label, value, icon: Icon }: { label: string; value: string; icon: typeof Wrench }) { return <article className="maintenance-kpi"><div><span>{label}</span><strong>{value}</strong></div><Icon aria-hidden="true" /></article>; }
