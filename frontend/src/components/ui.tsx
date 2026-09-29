import type { ReactNode } from 'react';
import { CircleAlert, Inbox, RotateCcw } from 'lucide-react';

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

export function PaginationSkeleton({ className = '' }: { className?: string }) {
  return <div className={`pagination-skeleton ${className}`.trim()} aria-hidden="true"><Skeleton className="pagination-skeleton__summary" /><span><Skeleton /><Skeleton /><Skeleton /></span></div>;
}

export function DetailSkeleton({ sections }: { sections: string[] }) {
  return <div className="detail-skeleton" role="status" aria-label="Memuat detail"><section className="detail-skeleton__hero"><Skeleton className="detail-skeleton__label" /><Skeleton className="detail-skeleton__title" /><Skeleton className="detail-skeleton__description" /></section>{sections.map((section) => <section className="detail-skeleton__section" key={section}><h2>{section}</h2><div>{[0, 1, 2, 3].map((field) => <span key={field}><Skeleton className="detail-skeleton__field-label" /><Skeleton className="detail-skeleton__field-value" /></span>)}</div></section>)}</div>;
}

export function FormSkeleton({ fields = 4 }: { fields?: number }) {
  return <div className="form-skeleton" role="status" aria-label="Memuat form">{Array.from({ length: fields }, (_, index) => <label className="form-skeleton__field" key={index}><Skeleton className="form-skeleton__label" /><Skeleton className={index === fields - 1 ? 'form-skeleton__input form-skeleton__input--large' : 'form-skeleton__input'} /></label>)}</div>;
}

export function EmptyState({ title, description }: { title: string; description: string }) {
  return (
    <div className="state-panel state-panel--empty" role="status">
      <span className="state-mark" aria-hidden="true"><Inbox size={20} /></span>
      <strong>{title}</strong>
      <span>{description}</span>
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

export function StatusBadge({ children, tone = 'neutral' }: { children: ReactNode; tone?: 'neutral' | 'success' | 'warning' | 'danger' }) {
  return <span className={`status-badge status-badge--${tone}`}>{children}</span>;
}
