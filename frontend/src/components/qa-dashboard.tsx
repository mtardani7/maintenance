'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { getQADefects, qaApiMessage } from '@/lib/qa-api';
import { type QADefect, type QADefectSeverity, type QAMaintenanceFilter } from '@/lib/qa-types';
import { EmptyState, ErrorState, PaginationSkeleton, TableSkeleton } from './ui';

const filterLabels: Record<QAMaintenanceFilter, string> = {
  all: 'Semua temuan', 'has-ticket': 'Sudah ada tiket', 'no-ticket': 'Belum ada tiket',
  open: 'Terbuka', 'in-progress': 'Sedang dikerjakan', resolved: 'Selesai', closed: 'Ditutup',
};
const severityLabels: Record<string, string> = { CRITICAL: 'Kritis', HIGH: 'Tinggi', MEDIUM: 'Sedang', LOW: 'Rendah' };
const maintenanceLabels: Record<string, string> = { OPEN: 'Terbuka', ASSIGNED: 'Ditugaskan', IN_PROGRESS: 'Sedang dikerjakan', RESOLVED: 'Selesai', VERIFIED: 'Terverifikasi', CLOSED: 'Ditutup' };

export function QADashboard() {
  const [defects, setDefects] = useState<QADefect[]>([]);
  const [filter, setFilter] = useState<QAMaintenanceFilter>('all');
  const [page, setPage] = useState(1);
  const [pageInfo, setPageInfo] = useState({ current: 1, last: 1, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  useEffect(() => {
    let active = true; setLoading(true); setError('');
    getQADefects(page, filter).then((result) => {
      if (!active) return;
      setDefects(result.data); setPageInfo({ current: result.currentPage, last: result.lastPage, total: result.total });
    }).catch((reason) => { if (active) setError(qaApiMessage(reason)); }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [filter, page, retryKey]);

  function changeFilter(value: QAMaintenanceFilter) { setFilter(value); setPage(1); }
  return <div className="qa-dashboard">
    <div className="qa-filters"><label>Status pemeliharaan<select value={filter} onChange={(event) => changeFilter(event.target.value as QAMaintenanceFilter)}>{Object.entries(filterLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label></div>
    {loading ? <TableSkeleton variant="grid" className="qa-defect-table" headerClassName="qa-defect-head" rowClassName="qa-defect-row" headers={["Temuan", "Mesin / Plant", "Tingkat masalah", "Status pemeliharaan"]} rows={6} /> : error ? <ErrorState title="Temuan mutu tidak tersedia" description="Periksa koneksi lalu coba muat ulang." onRetry={() => setRetryKey((value) => value + 1)} /> : defects.length === 0 ? <EmptyState title="Belum ada temuan" description="Tidak ada temuan yang sesuai dengan status ini." /> : <>
      <div className="qa-defect-table"><div className="qa-defect-head"><span>Temuan</span><span>Mesin / Plant</span><span>Tingkat masalah</span><span>Status pemeliharaan</span></div>{defects.map((defect) => <Link key={defect.id} href={`/qa/defects/${defect.id}`} className="qa-defect-row"><span><strong>{defect.defectId}</strong><small>{defect.defectType}</small></span><span>{defect.machine.code}<small>{defect.plant}</small></span><span><b className={`priority-dot priority-dot--${(defect.severity as QADefectSeverity).toLowerCase()}`} />{severityLabels[defect.severity] ?? defect.severity}</span><span>{defect.maintenanceTicket ? <><b className="qa-ticket-link">{defect.maintenanceTicket.number}</b><small>{maintenanceLabels[defect.maintenanceTicket.status] ?? defect.maintenanceTicket.status}</small></> : <span className="qa-no-ticket">Belum ada tiket</span>}</span></Link>)}</div>
      <div className="pagination"><span>Menampilkan {Math.min((pageInfo.current - 1) * 10 + 1, pageInfo.total)}–{Math.min(pageInfo.current * 10, pageInfo.total)} dari {pageInfo.total} temuan</span><div className="pagination__pages"><button className="secondary-action" type="button" disabled={pageInfo.current <= 1} onClick={() => setPage((value) => value - 1)}>Sebelumnya</button><span>{pageInfo.current} / {pageInfo.last}</span><button className="secondary-action" type="button" disabled={pageInfo.current >= pageInfo.last} onClick={() => setPage((value) => value + 1)}>Berikutnya</button></div></div>
    </>}
    {loading && <PaginationSkeleton className="pagination" />}
  </div>;
}
