'use client';

import { Eye, RefreshCw, RotateCcw, Search, Settings2 } from 'lucide-react';
import Link from 'next/link';
import { useEffect, useState } from 'react';
import { getPlantOptions, type PlantOption } from '@/lib/maintenance-api';
import { apiMessage, getTickets } from '@/lib/ticket-api';
import { ticketPriorities, ticketStatuses, type Ticket, type TicketFilters, type TicketPriority, type TicketStatus } from '@/lib/ticket-types';
import type { ReactNode } from 'react';
import { DataPagination, EmptyState, ErrorState, MaintenanceFilterBar, MaintenanceTable, PaginationSkeleton, StatusBadge, TableSkeleton } from './ui';

const statusLabels: Record<TicketStatus, string> = { OPEN: 'OPEN', CLOSED: 'CLOSED' };
const priorityLabels: Record<TicketPriority, string> = { CRITICAL: 'Kritis', HIGH: 'Tinggi', MEDIUM: 'Sedang', LOW: 'Rendah' };
const problemLabels: Record<string, string> = { 'Machine stopped': 'Mesin berhenti', 'Abnormal sound': 'Suara tidak normal', 'Sensor problem': 'Masalah sensor', 'Quality problem': 'Masalah mutu', Other: 'Lainnya' };
function formatTicketDate(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta' }).format(date);
}

export function TicketList({ header }: { header: ReactNode }) {
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [filters, setFilters] = useState<TicketFilters>({ sort: 'newest', page: 1, perPage: 10 });
  const [search, setSearch] = useState('');
  const [plants, setPlants] = useState<PlantOption[]>([]);
  const [pageInfo, setPageInfo] = useState({ current: 1, last: 1, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  useEffect(() => { getPlantOptions().then(setPlants).catch(() => setPlants([])); }, []);
  useEffect(() => {
    let active = true;
    setLoading(true);
    getTickets(filters).then((page) => {
      if (!active) return;
      setTickets(page.data);
      setPageInfo({ current: page.currentPage, last: page.lastPage, total: page.total });
      setError('');
    }).catch((reason) => { if (active) setError(apiMessage(reason)); }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [filters, retryKey]);
  useEffect(() => {
    const timer = window.setTimeout(() => setFilters((current) => ({ ...current, search: search.trim() || undefined, page: 1 })), 350);
    return () => window.clearTimeout(timer);
  }, [search]);

  function update(key: keyof TicketFilters, value: string) { setFilters((current) => ({ ...current, [key]: value, page: 1 })); }
  function resetFilters() { setSearch(''); setFilters({ sort: 'newest', page: 1, perPage: 10 }); }
  const hasActiveFilters = Boolean(search.trim() || filters.status || filters.priority || filters.plant || filters.sort !== 'newest');

  const perPage = filters.perPage ?? 10;
  const firstItem = pageInfo.total === 0 ? 0 : (pageInfo.current - 1) * perPage + 1;
  const lastItem = pageInfo.total === 0 ? 0 : Math.min(pageInfo.current * perPage, pageInfo.total);
  return <div className="ticket-browser">
    <div className="ticket-page-header">
      <div className="ticket-page-heading">{header}</div>
      <div className="ticket-page-actions">
      <button className="secondary-action incident-refresh-button ticket-refresh-button" type="button" onClick={() => setRetryKey((value) => value + 1)} disabled={loading} aria-label="Muat ulang" title="Muat ulang"><RefreshCw aria-hidden="true" /></button>
      </div>
    </div>
    <MaintenanceFilterBar className="ticket-filters" label="Filter tiket">
      <label className="filter-search incident-filter-search"><Search aria-hidden="true" /><input aria-label="Cari tiket" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari tiket, mesin, atau masalah..." /></label>
      <label>Status<select value={filters.status ?? ''} onChange={(event) => update('status', event.target.value as TicketStatus | '')}><option value="">Semua status</option>{ticketStatuses.map((status) => <option key={status} value={status}>{statusLabels[status]}</option>)}</select></label>
      <label>Plant<select value={filters.plant ?? ''} onChange={(event) => update('plant', event.target.value)}><option value="">Semua Plant</option>{plants.map((plant) => <option key={plant.id} value={plant.id}>{plant.code} — {plant.name}</option>)}</select></label>
      <details className={`ticket-more-filters${filters.priority || filters.sort !== 'newest' ? ' ticket-more-filters--active' : ''}`}><summary><Settings2 aria-hidden="true" /><span>Filter lainnya</span></summary><div><label>Prioritas<select value={filters.priority ?? ''} onChange={(event) => update('priority', event.target.value as TicketPriority | '')}><option value="">Semua prioritas</option>{ticketPriorities.map((priority) => <option key={priority} value={priority}>{priorityLabels[priority]}</option>)}</select></label><label>Urutan<select value={filters.sort ?? 'newest'} onChange={(event) => update('sort', event.target.value)}><option value="newest">Terbaru</option><option value="oldest">Terlama</option></select></label></div></details>
      {hasActiveFilters && <button className="secondary-action ticket-filter-reset filter-reset-button" type="button" onClick={resetFilters}><RotateCcw aria-hidden="true" />Reset filter</button>}
    </MaintenanceFilterBar>
    {loading ? <><PaginationSkeleton className="ticket-pagination ticket-pagination-skeleton maintenance-pagination-skeleton" /><div className="ticket-results"><TableSkeleton className="ticket-table-wrap" tableClassName="ticket-data-table" headers={["Nomor tiket", "Plant / Mesin", "Masalah", "Status", "Aksi"]} rows={6} /></div></> : error ? <ErrorState title="Data tidak dapat dimuat" description="Coba lagi beberapa saat." onRetry={() => setRetryKey((value) => value + 1)} /> : tickets.length === 0 ? <EmptyState className="ticket-empty" title="Belum ada tiket pemeliharaan" description="Belum ada tiket yang sesuai." action={hasActiveFilters ? <button className="secondary-action filter-reset-button" type="button" onClick={resetFilters}><RotateCcw aria-hidden="true" />Reset filter</button> : undefined} /> : <>
      <DataPagination currentPage={pageInfo.current} totalPages={pageInfo.last} onPageChange={(page) => setFilters((current) => ({ ...current, page }))} pageSize={perPage} onPageSizeChange={(pageSize) => setFilters((current) => ({ ...current, perPage: pageSize, page: 1 }))} summary={`Menampilkan ${firstItem}–${lastItem} dari ${pageInfo.total} tiket`} mobileSummary={`${firstItem}–${lastItem} dari ${pageInfo.total}`} />
      <div className="ticket-results">
      <MaintenanceTable containerClassName="ticket-table-wrap" className="ticket-data-table" label="Daftar tiket"><thead><tr><th>Nomor tiket</th><th>Plant / Mesin</th><th>Masalah</th><th>Status</th><th>Aksi</th></tr></thead><tbody>{tickets.map((ticket) => <tr key={ticket.id}>
          <td><strong>{ticket.number}</strong><small>{formatTicketDate(ticket.createdAt)}</small></td>
          <td><strong>{ticket.machine.code} / {ticket.machine.name}</strong><small>{ticket.plant}</small></td>
          <td className="ticket-data-table__problem" title={ticket.description}><strong>{problemLabels[ticket.problemType] ?? ticket.problemType}</strong><small>{ticket.description || '—'}</small></td>
          <td><StatusBadge className="maintenance-status-badge" tone={ticket.status === 'OPEN' ? 'success' : 'neutral'}>{statusLabels[ticket.status]}</StatusBadge></td>
          <td><Link href={`/tickets/${encodeURIComponent(String(ticket.id))}`} className="ticket-view-action" aria-label={`Lihat tiket ${ticket.number}`}><Eye aria-hidden="true" /><span>Lihat</span></Link></td>
        </tr>)}</tbody></MaintenanceTable>
      </div>
    </>}
  </div>;
}
