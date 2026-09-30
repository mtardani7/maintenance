import type { ReactNode } from 'react';
import { ChevronLeft, ChevronRight, CircleAlert, Inbox, RotateCcw } from 'lucide-react';

export function LoadingState({ label = 'Memuat ruang kerja' }: { label?: string }) {
  return (
    <div className="page-loading" role="status" aria-live="polite" aria-label={label}>
      <span className="sr-only">{label}</span>
      <div className="page-loading__heading"><Skeleton className="page-loading__eyebrow" /><Skeleton className="page-loading__title" /><Skeleton className="page-loading__description" /></div>
      <div className="page-loading__cards">{[0, 1, 2, 3].map((item) => <CardSkeleton key={item} />)}</div>
      <div className="page-loading__content"><Skeleton className="page-loading__line" /><Skeleton className="page-loading__line page-loading__line--short" /><Skeleton className="page-loading__block" /></div>
    </div>
  );
}

export function Skeleton({ className = '', width }: { className?: string; width?: string }) {
  return <span className={`skeleton ${className}`.trim()} aria-hidden="true" style={width ? { width } : undefined} />;
}

export function CardSkeleton({ className = '' }: { className?: string }) {
  return <article className={`card-skeleton ${className}`.trim()} aria-hidden="true"><Skeleton className="card-skeleton__icon" /><Skeleton className="card-skeleton__label" /><Skeleton className="card-skeleton__value" /><Skeleton className="card-skeleton__caption" /></article>;
}

export function ListSkeleton({ rows = 5, className = '' }: { rows?: number; className?: string }) {
  return <div className={`list-skeleton ${className}`.trim()} role="status" aria-label="Memuat daftar">{Array.from({ length: rows }, (_, index) => <div className="list-skeleton__item" key={index}><Skeleton className="list-skeleton__avatar" /><div><Skeleton className="list-skeleton__title" /><Skeleton className="list-skeleton__description" /></div><Skeleton className="list-skeleton__meta" /></div>)}</div>;
}

export function TableSkeleton({
  headers,
  rows = 6,
  className = '',
  tableClassName = '',
  headerClassName = '',
  rowClassName = '',
  variant = 'table',
}: {
  headers: string[];
  rows?: number;
  className?: string;
  tableClassName?: string;
  headerClassName?: string;
  rowClassName?: string;
  variant?: 'table' | 'grid';
}) {
  if (variant === 'grid') return <div className={`table-skeleton-grid ${className}`.trim()} role="status" aria-label="Memuat tabel"><div className={headerClassName}>{headers.map((header) => <span key={header}>{header}</span>)}</div>{Array.from({ length: rows }, (_, row) => <div className={`${rowClassName} skeleton-row`.trim()} key={row}>{headers.map((header, column) => <span key={header}><Skeleton className={column === 0 ? 'table-skeleton__primary' : 'table-skeleton__cell'} /></span>)}</div>)}</div>;
  return <div className={`table-skeleton ${className}`.trim()} role="status" aria-label="Memuat tabel"><table className={tableClassName}><thead><tr>{headers.map((header) => <th key={header}>{header}</th>)}</tr></thead><tbody>{Array.from({ length: rows }, (_, row) => <tr className="skeleton-row" key={row}>{headers.map((header, column) => <td key={header}><Skeleton className={column === 0 ? 'table-skeleton__primary' : 'table-skeleton__cell'} /></td>)}</tr>)}</tbody></table></div>;
}

export function MaintenanceTable({
  children,
  className = '',
  containerClassName = '',
  label,
}: {
  children: ReactNode;
  className?: string;
  containerClassName?: string;
  label: string;
}) {
  return (
    <div className={`maintenance-table-container ${containerClassName}`.trim()} role="region" aria-label={label}>
      <table className={`maintenance-table ${className}`.trim()}>{children}</table>
    </div>
  );
}

export function MaintenanceFilterBar({ children, className = '', label }: { children: ReactNode; className?: string; label?: string }) {
  return <div className={`maintenance-filter-bar ${className}`.trim()} aria-label={label}>{children}</div>;
}

export function DataPagination({
  currentPage,
  totalPages,
  summary,
  mobileSummary = summary,
  pageSize,
  pageSizeOptions = [10, 25, 50, 100],
  onPageSizeChange,
  onPageChange,
}: {
  currentPage: number;
  totalPages: number;
  summary: ReactNode;
  mobileSummary?: ReactNode;
  pageSize?: number;
  pageSizeOptions?: number[];
  onPageSizeChange?: (pageSize: number) => void;
  onPageChange: (page: number) => void;
}) {
  const pages = Array.from({ length: totalPages }, (_, index) => index + 1).filter((page) => page === 1 || page === totalPages || Math.abs(page - currentPage) <= 1);
  return <nav className="ticket-pagination maintenance-pagination" aria-label="Navigasi halaman">
    {pageSize !== undefined && onPageSizeChange && <label className="pagination-page-size maintenance-pagination__page-size">Per halaman<select aria-label="Jumlah data per halaman" value={pageSize} onChange={(event) => onPageSizeChange(Number(event.target.value))}>{pageSizeOptions.map((option) => <option key={option} value={option}>{option}</option>)}</select></label>}
    <span className="ticket-pagination__summary"><span className="maintenance-pagination__desktop-summary">{summary}</span><span className="maintenance-pagination__mobile-summary">{mobileSummary}</span></span>
    <div className="ticket-pagination__controls">
      <button className="ticket-pagination__arrow" type="button" aria-label="Halaman sebelumnya" disabled={currentPage <= 1} onClick={() => onPageChange(currentPage - 1)}><ChevronLeft aria-hidden="true" /><span>Sebelumnya</span></button>
      <div className="ticket-pagination__pages">{pages.map((page, index) => <span key={page}>{index > 0 && page - pages[index - 1] > 1 ? <b aria-hidden="true">…</b> : null}<button className={page === currentPage ? 'is-current' : ''} type="button" aria-current={page === currentPage ? 'page' : undefined} onClick={() => onPageChange(page)}>{page}</button></span>)}</div>
      <span className="ticket-pagination__mobile-current">{currentPage} / {totalPages}</span>
      <button className="ticket-pagination__arrow" type="button" aria-label="Halaman berikutnya" disabled={currentPage >= totalPages} onClick={() => onPageChange(currentPage + 1)}><span>Berikutnya</span><ChevronRight aria-hidden="true" /></button>
    </div>
  </nav>;
}

export function PaginationSkeleton({ className = '' }: { className?: string }) {
  return <div className={`pagination-skeleton ${className}`.trim()} aria-hidden="true"><Skeleton className="pagination-skeleton__summary" /><span><Skeleton /><Skeleton /><Skeleton /></span></div>;
}

export function DetailSkeleton({ sections }: { sections: string[] }) {
  return <div className="detail-skeleton" role="status" aria-label="Memuat detail"><section className="detail-skeleton__hero"><Skeleton className="detail-skeleton__label" /><Skeleton className="detail-skeleton__title" /><Skeleton className="detail-skeleton__description" /></section>{sections.map((section) => <section className="detail-skeleton__section" key={section}><h2>{section}</h2><div>{[0, 1, 2, 3].map((field) => <span key={field}><Skeleton className="detail-skeleton__field-label" /><Skeleton className="detail-skeleton__field-value" /></span>)}</div></section>)}</div>;
}

export function FormSkeleton({ fields = 4 }: { fields?: number }) {
  return <div className="form-skeleton" role="status" aria-label="Memuat form">{Array.from({ length: fields }, (_, index) => <label className="form-skeleton__field" key={index}><Skeleton className="form-skeleton__label" /><Skeleton className={index === fields - 1 ? 'form-skeleton__input form-skeleton__input--large' : 'form-skeleton__input'} /></label>)}</div>;
}

export function EmptyState({ title, description, action, className = '' }: { title: string; description: string; action?: ReactNode; className?: string }) {
  return (
    <div className={`state-panel state-panel--empty ${className}`.trim()} role="status">
      <span className="state-mark" aria-hidden="true"><Inbox size={20} /></span>
      <strong>{title}</strong>
      <span>{description}</span>
      {action}
    </div>
  );
}

export function ErrorState({ title = 'Data tidak dapat dimuat', description, onRetry }: { title?: string; description: string; onRetry?: () => void }) {
  return (
    <div className="state-panel state-panel--error" role="alert">
      <span className="state-mark" aria-hidden="true"><CircleAlert size={20} /></span>
      <strong>{title}</strong>
      <span>{description}</span>
      {onRetry && <button className="secondary-action state-retry" type="button" onClick={onRetry}><RotateCcw aria-hidden="true" />Coba lagi</button>}
    </div>
  );
}

export function SectionHeading({ eyebrow, title, description, action }: { eyebrow?: string; title: string; description?: string; action?: ReactNode }) {
  return (
    <header className="section-heading">
      <div>
        {eyebrow && <p className="eyebrow">{eyebrow}</p>}
        <h1>{title}</h1>
        {description && <p className="section-heading-description">{description}</p>}
      </div>
      {action}
    </header>
  );
}

export function SurfaceCard({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <section className={`surface-card ${className}`}>{children}</section>;
}

export function DetailCard({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <section className={`detail-surface ${className}`}>{children}</section>;
}

export function StatusBadge({ children, tone = 'neutral', className = '' }: { children: ReactNode; tone?: 'neutral' | 'success' | 'warning' | 'danger'; className?: string }) {
  return <span className={`status-badge status-badge--${tone} ${className}`.trim()}>{children}</span>;
}
