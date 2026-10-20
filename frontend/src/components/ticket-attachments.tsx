'use client';

import { useCallback, useEffect, useMemo, useRef, useState, type ImgHTMLAttributes, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { ChevronLeft, ChevronRight, Play, Upload, Video, X } from 'lucide-react';
import type { Ticket } from '@/lib/ticket-types';
import { getAttachmentPreviewType } from '@/lib/media-attachments';
import { getTicketAttachmentPreview, uploadTicketAttachments } from '@/lib/ticket-api';
import { MediaAttachmentPicker } from './media-attachment-picker';
import { ProtectedAttachmentLink } from './protected-attachment-link';

type Attachment = NonNullable<Ticket['attachments']>[number];

export function TicketAttachments({ ticketId, attachments = [], editable, onUploaded }: { ticketId: Ticket['number']; attachments?: NonNullable<Ticket['attachments']>; editable: boolean; onUploaded: (attachments: NonNullable<Ticket['attachments']>) => void }) {
  const [files, setFiles] = useState<File[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [previewUrls, setPreviewUrls] = useState<Record<string, string>>({});
  const [previewErrors, setPreviewErrors] = useState<Record<string, boolean>>({});
  const [loading, setLoading] = useState<Record<string, boolean>>({});
  const [selectedIndex, setSelectedIndex] = useState<number | null>(null);
  const [videoError, setVideoError] = useState(false);
  const urlMap = useRef(new Map<string, string>());
  const pendingLoads = useRef(new Map<string, Promise<void>>());
  const mounted = useRef(true);

  const ensurePreview = useCallback((attachment: Attachment) => {
    const key = String(attachment.id);
    if (urlMap.current.has(key)) return Promise.resolve();
    const pending = pendingLoads.current.get(key);
    if (pending) return pending;

    setLoading((current) => ({ ...current, [key]: true }));
    const request = getTicketAttachmentPreview(attachment.url)
      .then((blob) => {
        const objectUrl = URL.createObjectURL(blob);
        if (!mounted.current) {
          URL.revokeObjectURL(objectUrl);
          return;
        }
        urlMap.current.set(key, objectUrl);
        setPreviewUrls((current) => ({ ...current, [key]: objectUrl }));
        setPreviewErrors((current) => ({ ...current, [key]: false }));
      })
      .catch(() => {
        if (mounted.current) setPreviewErrors((current) => ({ ...current, [key]: true }));
      })
      .finally(() => {
        pendingLoads.current.delete(key);
        if (mounted.current) setLoading((current) => ({ ...current, [key]: false }));
      });
    pendingLoads.current.set(key, request);
    return request;
  }, []);

  useEffect(() => {
    const objectUrls = urlMap.current;
    mounted.current = true;
    return () => {
      mounted.current = false;
      objectUrls.forEach((url) => URL.revokeObjectURL(url));
      objectUrls.clear();
    };
  }, []);

  const mediaIndexes = useMemo(() => attachments
    .map((item, index) => getAttachmentPreviewType(item.mime_type, item.file_name) ? index : -1)
    .filter((index) => index >= 0), [attachments]);
  const selected = selectedIndex === null ? null : attachments[selectedIndex] ?? null;
  const selectedType = selected ? getAttachmentPreviewType(selected.mime_type, selected.file_name) : null;
  const selectedUrl = selected ? previewUrls[String(selected.id)] : undefined;

  useEffect(() => {
    if (!selected) return;
    setVideoError(false);
    void ensurePreview(selected);
  }, [ensurePreview, selected]);

  useEffect(() => {
    if (selectedIndex === null) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setSelectedIndex(null);
      if (event.key === 'ArrowLeft') moveSelection(-1);
      if (event.key === 'ArrowRight') moveSelection(1);
    };
    window.addEventListener('keydown', onKeyDown);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      window.removeEventListener('keydown', onKeyDown);
      document.body.style.overflow = previousOverflow;
    };
    // moveSelection is stable for the active index and only invoked by keyboard events.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedIndex]);

  function moveSelection(direction: -1 | 1) {
    if (selectedIndex === null || mediaIndexes.length < 2) return;
    const position = mediaIndexes.indexOf(selectedIndex);
    const nextPosition = (position + direction + mediaIndexes.length) % mediaIndexes.length;
    setSelectedIndex(mediaIndexes[nextPosition]);
  }

  async function upload() {
    if (!files.length || busy) return;
    setBusy(true); setError('');
    try {
      const response = await uploadTicketAttachments(ticketId, files);
      onUploaded([...attachments, ...response.data]);
      setFiles([]);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Lampiran gagal diunggah.');
    } finally { setBusy(false); }
  }

  return <section className="ticket-info-card ticket-detail-section media-ticket-attachments">
    <div className="ticket-detail-section-heading"><h2>Dokumentasi Foto/Video</h2><p>Foto kondisi dan hasil pekerjaan.</p></div>
    {attachments.length > 0 ? <ul className="media-attachment-files ticket-attachment-list">{attachments.map((item, index) => {
      const type = getAttachmentPreviewType(item.mime_type, item.file_name);
      const key = String(item.id);
      if (!type) return <li className="ticket-attachment-file" key={item.id}><span className="ticket-attachment-name" title={item.file_name}>{item.file_name}</span><ProtectedAttachmentLink url={item.url} fileName={item.file_name} label="Unduh" /><small>{(item.file_size / 1024 / 1024).toFixed(1)} MB</small></li>;

      return <LazyAttachmentRow key={item.id} onVisible={() => void ensurePreview(item)}>
        <div className="ticket-attachment-media">
          <button className="ticket-attachment-preview" type="button" onClick={() => setSelectedIndex(index)} aria-label={type === 'image' ? `Perbesar foto ${item.file_name}` : `Putar video ${item.file_name}`}>
            {previewUrls[key] && !previewErrors[key] && type === 'image' ? <AttachmentImage src={previewUrls[key]} alt={item.file_name} loading="lazy" /> : null}
            {type === 'video' && <span className="ticket-attachment-video-tile"><Video aria-hidden="true" /><span><Play aria-hidden="true" fill="currentColor" /></span></span>}
            {type === 'image' && (!previewUrls[key] || previewErrors[key]) && <span className="ticket-attachment-loading">{loading[key] ? 'Memuat…' : previewErrors[key] ? 'Preview tidak tersedia' : 'Foto'}</span>}
            {type === 'video' && loading[key] && <span className="ticket-attachment-loading">Memuat…</span>}
          </button>
          <div className="ticket-attachment-copy">
            <span className="ticket-attachment-name" title={item.file_name}>{item.file_name}</span>
            <small>{(item.file_size / 1024 / 1024).toFixed(1)} MB</small>
            <ProtectedAttachmentLink url={item.url} fileName={item.file_name} label="Unduh" />
          </div>
        </div>
        {previewErrors[key] && <small className="ticket-attachment-error" role="status">Preview tidak tersedia. Anda masih dapat mengunduh file.</small>}
      </LazyAttachmentRow>;
    })}</ul> : <p className="spare-parts-empty">Belum ada lampiran.</p>}
    {editable && <>
      <MediaAttachmentPicker files={files} onChange={setFiles} disabled={busy || attachments.length >= 5} maxAttachments={Math.max(0, 5 - attachments.length)} />
      {error && <p className="form-inline-error" role="alert">{error}</p>}
      {files.length > 0 && <button className="primary-button" type="button" onClick={() => void upload()} disabled={busy}><Upload aria-hidden="true" />{busy ? 'Mengunggah...' : 'Unggah Lampiran'}</button>}
    </>}
    {selected && selectedType && typeof document !== 'undefined' ? createPortal(<div className="attachment-lightbox" role="presentation" onClick={() => setSelectedIndex(null)}>
      <section className="attachment-lightbox__dialog" role="dialog" aria-modal="true" aria-label={`Preview ${selected.file_name}`}>
        <header className="attachment-lightbox__header"><span title={selected.file_name}>{selected.file_name}</span><button type="button" onClick={(event) => { event.stopPropagation(); setSelectedIndex(null); }} aria-label="Tutup preview"><X aria-hidden="true" /></button></header>
        <div className="attachment-lightbox__stage">
          {mediaIndexes.length > 1 && <button type="button" className="attachment-lightbox__nav attachment-lightbox__nav--previous" onClick={(event) => { event.stopPropagation(); moveSelection(-1); }} aria-label="Foto sebelumnya"><ChevronLeft aria-hidden="true" /></button>}
          {selectedUrl && !previewErrors[String(selected.id)] && selectedType === 'image' && <AttachmentImage src={selectedUrl} alt={selected.file_name} onClick={(event) => event.stopPropagation()} onError={() => setPreviewErrors((current) => ({ ...current, [String(selected.id)]: true }))} />}
          {selectedUrl && selectedType === 'video' && !videoError && <video key={selected.id} src={selectedUrl} controls autoPlay playsInline onClick={(event) => event.stopPropagation()} onError={() => setVideoError(true)} />}
          {!selectedUrl && !previewErrors[String(selected.id)] && <p role="status">Memuat preview…</p>}
          {videoError && <div className="attachment-lightbox__fallback" onClick={(event) => event.stopPropagation()}><p>Video tidak dapat diputar di browser ini.</p><ProtectedAttachmentLink url={selected.url} fileName={selected.file_name} label="Unduh video" /></div>}
          {previewErrors[String(selected.id)] && <div className="attachment-lightbox__fallback" onClick={(event) => event.stopPropagation()}><p>File tidak ditemukan atau tidak dapat dimuat.</p><ProtectedAttachmentLink url={selected.url} fileName={selected.file_name} label="Coba unduh" /></div>}
          {mediaIndexes.length > 1 && <button type="button" className="attachment-lightbox__nav attachment-lightbox__nav--next" onClick={(event) => { event.stopPropagation(); moveSelection(1); }} aria-label="Foto berikutnya"><ChevronRight aria-hidden="true" /></button>}
        </div>
        {mediaIndexes.length > 1 && <p className="attachment-lightbox__count">{mediaIndexes.indexOf(selectedIndex ?? -1) + 1} / {mediaIndexes.length}</p>}
      </section>
    </div>, document.body) : null}
  </section>;
}

function AttachmentImage({ alt, ...props }: ImgHTMLAttributes<HTMLImageElement> & { alt: string }) {
  // Blob URLs contain protected API responses and cannot use Next's remote image optimizer.
  // eslint-disable-next-line @next/next/no-img-element
  return <img {...props} alt={alt} />;
}

function LazyAttachmentRow({ children, onVisible }: { children: ReactNode; onVisible: () => void }) {
  const rowRef = useRef<HTMLLIElement>(null);
  useEffect(() => {
    const element = rowRef.current;
    if (!element) return;
    if (!('IntersectionObserver' in window)) {
      onVisible();
      return;
    }
    const observer = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) {
        onVisible();
        observer.disconnect();
      }
    }, { rootMargin: '120px' });
    observer.observe(element);
    return () => observer.disconnect();
  }, [onVisible]);
  return <li className="ticket-attachment-file ticket-attachment-file--media" ref={rowRef}>{children}</li>;
}
