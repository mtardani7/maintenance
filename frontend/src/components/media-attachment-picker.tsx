'use client';

import { useRef, useState } from 'react';
import { Camera, ImagePlus, X } from 'lucide-react';
import { MAX_ATTACHMENTS, prepareMedia, isSupportedMedia, validateAttachmentSelection } from '@/lib/media-attachments';

export function MediaAttachmentPicker({ files, onChange, disabled = false, maxAttachments = MAX_ATTACHMENTS }: { files: File[]; onChange: (files: File[]) => void; disabled?: boolean; maxAttachments?: number }) {
  const cameraRef = useRef<HTMLInputElement>(null);
  const galleryRef = useRef<HTMLInputElement>(null);
  const [status, setStatus] = useState('');
  const [error, setError] = useState('');
  const [working, setWorking] = useState(false);

  async function addFiles(list: FileList | null) {
    if (!list?.length) return;
    setError('');
    setWorking(true);
    const incoming = Array.from(list);
    if (files.length + incoming.length > maxAttachments) {
      setError(`Maksimal ${maxAttachments} lampiran.`); setWorking(false); return;
    }
    const unsupported = incoming.find((file) => !isSupportedMedia(file) || file.size > (file.type.startsWith('image/') ? 50 : 18) * 1024 * 1024);
    if (unsupported) {
      setError(!isSupportedMedia(unsupported) ? 'Gunakan foto JPG, PNG, WEBP atau video MP4, MOV, WEBM.' : `${unsupported.name}: ukuran file terlalu besar.`); setWorking(false); return;
    }
    try {
      const prepared: File[] = [];
      for (const file of incoming) {
        if (file.type.startsWith('image/')) setStatus('Mengoptimalkan foto...');
        else setStatus('Menyiapkan video...');
        prepared.push(await prepareMedia(file));
      }
      const result = [...files, ...prepared];
      const validation = validateAttachmentSelection(result);
      if (validation) throw new Error(validation);
      onChange(result);
      setStatus('Lampiran siap diunggah.');
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'File tidak dapat disiapkan.');
      setStatus('');
    } finally {
      setWorking(false);
      if (cameraRef.current) cameraRef.current.value = '';
      if (galleryRef.current) galleryRef.current.value = '';
    }
  }

  return <div className="media-attachment-picker">
    <div className="media-attachment-actions">
      <button type="button" className="secondary-action" onClick={() => cameraRef.current?.click()} disabled={disabled || working || files.length >= maxAttachments}><Camera aria-hidden="true" />Ambil Foto</button>
      <button type="button" className="secondary-action" onClick={() => galleryRef.current?.click()} disabled={disabled || working || files.length >= maxAttachments}><ImagePlus aria-hidden="true" />Pilih Foto/Video</button>
    </div>
    <input ref={cameraRef} type="file" accept="image/jpeg,image/png,image/webp" capture="environment" hidden onChange={(event) => void addFiles(event.target.files)} />
    <input ref={galleryRef} type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm,.mov" multiple hidden onChange={(event) => void addFiles(event.target.files)} />
    <p className="media-attachment-hint">Maksimal {maxAttachments} file, total 18 MB. Foto akan disiapkan otomatis.</p>
    {working && <p className="media-attachment-status" role="status">{status || 'Menyiapkan file...'}</p>}
    {!working && status && <p className="media-attachment-status" role="status">{status}</p>}
    {error && <p className="form-inline-error" role="alert">{error}</p>}
    {files.length > 0 && <ul className="media-attachment-files">{files.map((file, index) => <li key={`${file.name}-${index}`}><span>{file.name} · {(file.size / 1024 / 1024).toFixed(1)} MB</span><button type="button" aria-label={`Hapus ${file.name}`} onClick={() => { onChange(files.filter((_, itemIndex) => itemIndex !== index)); setStatus(''); }} disabled={disabled || working}><X aria-hidden="true" /></button></li>)}</ul>}
  </div>;
}
