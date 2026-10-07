'use client';

import { useState } from 'react';
import { Download } from 'lucide-react';
import { downloadTicketAttachment } from '@/lib/ticket-api';

export function ProtectedAttachmentLink({ url, fileName }: { url: string; fileName: string }) {
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  async function download() {
    if (busy) return;
    setBusy(true); setError('');
    try { await downloadTicketAttachment(url, fileName); }
    catch { setError('Lampiran tidak dapat diunduh.'); }
    finally { setBusy(false); }
  }
  return <span className="protected-attachment-link"><button type="button" onClick={() => void download()} disabled={busy}><Download aria-hidden="true" />{busy ? 'Mengunduh...' : fileName}</button>{error && <small role="alert">{error}</small>}</span>;
}
