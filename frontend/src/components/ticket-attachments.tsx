'use client';

import { useState } from 'react';
import { Upload } from 'lucide-react';
import type { Ticket } from '@/lib/ticket-types';
import { uploadTicketAttachments } from '@/lib/ticket-api';
import { MediaAttachmentPicker } from './media-attachment-picker';
import { ProtectedAttachmentLink } from './protected-attachment-link';

export function TicketAttachments({ ticketId, attachments = [], editable, onUploaded }: { ticketId: Ticket['id']; attachments?: NonNullable<Ticket['attachments']>; editable: boolean; onUploaded: (attachments: NonNullable<Ticket['attachments']>) => void }) {
  const [files, setFiles] = useState<File[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

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
    {attachments.length > 0 ? <ul className="media-attachment-files">{attachments.map((item) => <li key={item.id}><ProtectedAttachmentLink url={item.url} fileName={item.file_name} /><small>{(item.file_size / 1024 / 1024).toFixed(1)} MB</small></li>)}</ul> : <p className="spare-parts-empty">Belum ada lampiran.</p>}
    {editable && <><MediaAttachmentPicker files={files} onChange={setFiles} disabled={busy || attachments.length >= 5} maxAttachments={Math.max(0, 5 - attachments.length)} />{error && <p className="form-inline-error" role="alert">{error}</p>}{files.length > 0 && <button className="primary-button" type="button" onClick={() => void upload()} disabled={busy}><Upload aria-hidden="true" />{busy ? 'Mengunggah...' : 'Unggah Lampiran'}</button>}</>}
  </section>;
}
