'use client';

import { ChevronLeft, ChevronRight, Eye, Inbox, RotateCcw, Search } from 'lucide-react';
import Link from 'next/link';
import { useEffect, useState } from 'react';
import { getPlantOptions, type PlantOption } from '@/lib/maintenance-api';
import { apiMessage, getTickets } from '@/lib/ticket-api';
import { ticketPriorities, ticketStatuses, type Ticket, type TicketFilters, type TicketPriority, type TicketStatus } from '@/lib/ticket-types';
import { ErrorState, MaintenanceTable, PaginationSkeleton, TableSkeleton } from './ui';

const statusLabels: Record<TicketStatus, string> = { OPEN: 'OPEN', CLOSED: 'CLOSED' };
const priorityLabels: Record<TicketPriority, string> = { CRITICAL: 'Kritis', HIGH: 'Tinggi', MEDIUM: 'Sedang', LOW: 'Rendah' };
const problemLabels: Record<string, string> = { 'Machine stopped': 'Mesin berhenti', 'Abnormal sound': 'Suara tidak normal', 'Sensor problem': 'Masalah sensor', 'Quality problem': 'Masalah mutu', Other: 'Lainnya' };
function formatTicketDate(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta' }).format(date);
}

export function TicketList() {
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
  const hasActiveFilters = Boolean(search.trim() || filters.status || filters.priority || filters.plant);

  const perPage = filters.perPage ?? 10;
  const firstItem = pageInfo.total === 0 ? 0 : (pageInfo.current - 1) * perPage + 1;
  const lastItem = pageInfo.total === 0 ? 0 : Math.min(pageInfo.current * perPage, pageInfo.total);
  const pageNumbers = Array.from({ length: pageInfo.last }, (_, index) => index + 1).filter((currentPage) => currentPage === 1 || currentPage === pageInfo.last || Math.abs(currentPage - pageInfo.current) <= 1);

  return <div className="ticket-browser">
    <div className="ticket-filters" aria-label="Filter tiket">
      <label className="filter-search"><span><Search aria-hidden="true" />Cari tiket</span><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Nomor, mesin, atau masalah" /></label>
      <label>Status<select value={filters.status ?? ''} onChange={(event) => update('status', event.target.value as TicketStatus | '')}><option value="">Semua status</option>{ticketStatuses.map((status) => <option key={status} value={status}>{statusLabels[status]}</option>)}</select></label>
      <label>Plant<select value={filters.plant ?? ''} onChange={(event) => update('plant', event.target.value)}><option value="">Semua Plant</option>{plants.map((plant) => <option key={plant.id} value={plant.id}>{plant.code} — {plant.name}</option>)}</select></label>
      <label className="ticket-page-size-filter">Per halaman<select aria-label="Jumlah tiket per halaman" value={perPage} onChange={(event) => setFilters((current) => ({ ...current, perPage: Number(event.target.value), page: 1 }))}><option value={10}>10</option><option value={25}>25</option><option value={50}>50</option></select></label>
      <details className="ticket-more-filters"><summary>Filter lainnya</summary><div><label>Prioritas<select value={filters.priority ?? ''} onChange={(event) => update('priority', event.target.value as TicketPriority | '')}><option value="">Semua prioritas</option>{ticketPriorities.map((priority) => <option key={priority} value={priority}>{priorityLabels[priority]}</option>)}</select></label><label>Urutan<select value={filters.sort ?? 'newest'} onChange={(event) => update('sort', event.target.value)}><option value="newest">Terbaru</option><option value="oldest">Terlama</option></select></label></div></details>
      <button className="secondary-action ticket-filter-reset" type="button" onClick={resetFilters}><RotateCcw aria-hidden="true" />Reset filter</button>
    </div>
    {loading ? <div className="ticket-results"><TableSkeleton className="ticket-table-wrap" tableClassName="ticket-data-table" headers={["Nomor tiket", "Plant / Mesin", "Masalah", "Status", "Aksi"]} rows={6} /><PaginationSkeleton className="ticket-pagination ticket-pagination-skeleton" /></div> : error ? <ErrorState title="Data tidak dapat dimuat" description="Coba lagi beberapa saat." onRetry={() => setRetryKey((value) => value + 1)} /> : tickets.length === 0 ? <div className="ticket-empty state-panel state-panel--empty" role="status"><span className="state-mark" aria-hidden="true"><Inbox size={20} /></span><strong>Belum ada tiket pemeliharaan</strong><span>Belum ada ticket yang sesuai dengan filter.</span>{hasActiveFilters && <button className="secondary-action" type="button" onClick={resetFilters}><RotateCcw aria-hidden="true" />Reset Filter</button>}</div> : <div className="ticket-results">
      <MaintenanceTable containerClassName="ticket-table-wrap" className="ticket-data-table" label="Daftar tiket"><thead><tr><th>Nomor tiket</th><th>Plant / Mesin</th><th>Masalah</th><th>Status</th><th>Aksi</th></tr></thead><tbody>{tickets.map((ticket) => <tr key={ticket.id}>
          <td><strong>{ticket.number}</strong><small>{formatTicketDate(ticket.createdAt)}</small></td>
          <td><strong>{ticket.machine.code} / {ticket.machine.name}</strong><small>{ticket.plant}</small></td>
          <td className="ticket-data-table__problem" title={ticket.description}><strong>{problemLabels[ticket.problemType] ?? ticket.problemType}</strong><small>{ticket.description || '—'}</small></td>
          <td><b className={`ticket-list-status ticket-list-status--${ticket.status.toLowerCase()}`}>{statusLabels[ticket.status]}</b></td>
          <td><Link href={`/tickets/${encodeURIComponent(String(ticket.id))}`} className="ticket-view-action" aria-label={`Lihat tiket ${ticket.number}`}><Eye aria-hidden="true" /><span>Lihat</span></Link></td>
        </tr>)}</tbody></MaintenanceTable>
      <div className="ticket-pagination"><span className="ticket-pagination__summary">Menampilkan {firstItem}–{lastItem} dari {pageInfo.total} tiket</span><div className="ticket-pagination__controls"><button className="ticket-pagination__arrow" type="button" aria-label="Halaman sebelumnya" disabled={pageInfo.current <= 1} onClick={() => setFilters((current) => ({ ...current, page: pageInfo.current - 1 }))}><ChevronLeft aria-hidden="true" /><span>Sebelumnya</span></button><div className="ticket-pagination__pages">{pageNumbers.map((currentPage, index) => <span key={currentPage}>{index > 0 && currentPage - pageNumbers[index - 1] > 1 ? <b aria-hidden="true">…</b> : null}<button className={currentPage === pageInfo.current ? 'is-current' : ''} type="button" aria-current={currentPage === pageInfo.current ? 'page' : undefined} onClick={() => setFilters((current) => ({ ...current, page: currentPage }))}>{currentPage}</button></span>)}</div><span className="ticket-pagination__mobile-current">{pageInfo.current} / {pageInfo.last}</span><button className="ticket-pagination__arrow" type="button" aria-label="Halaman berikutnya" disabled={pageInfo.current >= pageInfo.last} onClick={() => setFilters((current) => ({ ...current, page: pageInfo.current + 1 }))}><span>Berikutnya</span><ChevronRight aria-hidden="true" /></button></div></div>
    </div>}
  </div>;
}
