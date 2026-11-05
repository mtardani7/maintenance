'use client';

import { useEffect, useRef, useState } from 'react';
import { Camera, CircleStop, ImagePlus, Video, X } from 'lucide-react';
import { MAX_ATTACHMENTS, prepareMedia, isSupportedMedia, validateAttachmentSelection } from '@/lib/media-attachments';

export function MediaAttachmentPicker({ files, onChange, disabled = false, maxAttachments = MAX_ATTACHMENTS }: { files: File[]; onChange: (files: File[]) => void; disabled?: boolean; maxAttachments?: number }) {
  const cameraRef = useRef<HTMLInputElement>(null);
  const videoCameraRef = useRef<HTMLInputElement>(null);
  const galleryRef = useRef<HTMLInputElement>(null);
  const previewRef = useRef<HTMLVideoElement>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const recorderRef = useRef<MediaRecorder | null>(null);
  const chunksRef = useRef<Blob[]>([]);
  const durationTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const [status, setStatus] = useState('');
  const [error, setError] = useState('');
  const [working, setWorking] = useState(false);
  const [cameraOpen, setCameraOpen] = useState(false);
  const [recording, setRecording] = useState(false);
  const [recordingSeconds, setRecordingSeconds] = useState(0);

  function closeCamera() {
    if (durationTimerRef.current) clearInterval(durationTimerRef.current);
    durationTimerRef.current = null;
    streamRef.current?.getTracks().forEach((track) => track.stop());
    streamRef.current = null;
    if (previewRef.current) previewRef.current.srcObject = null;
    setCameraOpen(false);
    setRecording(false);
    setRecordingSeconds(0);
  }

  useEffect(() => () => {
    if (durationTimerRef.current) clearInterval(durationTimerRef.current);
    streamRef.current?.getTracks().forEach((track) => track.stop());
  }, []);

  useEffect(() => {
    if (!cameraOpen || !previewRef.current || !streamRef.current) return;
    previewRef.current.srcObject = streamRef.current;
    void previewRef.current.play().catch(() => undefined);
  }, [cameraOpen]);

  async function openVideoCamera() {
    setError('');
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
      setError('Browser membatasi pratinjau kamera pada alamat HTTP. Membuka kamera perangkat; gunakan https://apps.rapidplast.co.id/mira untuk merekam langsung di halaman.');
      videoCameraRef.current?.click();
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        audio: true,
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
      });
      streamRef.current = stream;
      setCameraOpen(true);
    } catch {
      setError('Pratinjau kamera tidak dapat dibuka. Izinkan akses kamera dan mikrofon, atau gunakan kamera perangkat.');
    }
  }

  function startRecording() {
    const stream = streamRef.current;
    if (!stream) return;
    const mimeType = [
      'video/mp4;codecs=avc1.42E01E,mp4a.40.2',
      'video/mp4',
      'video/webm;codecs=vp8,opus',
      'video/webm',
    ].find((type) => MediaRecorder.isTypeSupported(type));
    if (!mimeType) {
      setError('Format rekaman tidak didukung browser ini. Pilih video dari galeri.');
      return;
    }
    try {
      chunksRef.current = [];
      const recorder = new MediaRecorder(stream, { mimeType, videoBitsPerSecond: 1_200_000, audioBitsPerSecond: 64_000 });
      recorderRef.current = recorder;
      recorder.ondataavailable = (event) => { if (event.data.size) chunksRef.current.push(event.data); };
      recorder.onstop = () => {
        const type = recorder.mimeType || mimeType;
        const extension = type.includes('mp4') ? 'mp4' : 'webm';
        const video = new File(chunksRef.current, `video-laporan-${Date.now()}.${extension}`, { type, lastModified: Date.now() });
        closeCamera();
        if (video.size) void addFiles([video]);
      };
      recorder.start(1000);
      setRecording(true);
      setRecordingSeconds(0);
      durationTimerRef.current = setInterval(() => {
        setRecordingSeconds((seconds) => {
          if (seconds + 1 >= 45 && recorder.state === 'recording') recorder.stop();
          return Math.min(seconds + 1, 45);
        });
      }, 1000);
    } catch {
      setError('Perekaman video gagal dimulai. Coba lagi atau pilih video dari galeri.');
    }
  }

  function finishRecording() {
    const recorder = recorderRef.current;
    if (recorder?.state === 'recording') recorder.stop();
  }

  function cancelRecording() {
    const recorder = recorderRef.current;
    if (recorder?.state === 'recording') {
      recorder.onstop = null;
      recorder.stop();
    }
    closeCamera();
  }

  async function addFiles(list: FileList | File[] | null) {
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
      <button type="button" className="secondary-action" onClick={() => void openVideoCamera()} disabled={disabled || working || cameraOpen || files.length >= maxAttachments}><Video aria-hidden="true" />Rekam Video</button>
      <button type="button" className="secondary-action" onClick={() => galleryRef.current?.click()} disabled={disabled || working || files.length >= maxAttachments}><ImagePlus aria-hidden="true" />Pilih Foto/Video</button>
    </div>
    <input ref={cameraRef} type="file" accept="image/jpeg,image/png,image/webp" capture="environment" hidden onChange={(event) => void addFiles(event.target.files)} />
    <input ref={videoCameraRef} type="file" accept="video/mp4,video/quicktime,video/webm" capture="environment" hidden onChange={(event) => void addFiles(event.target.files)} />
    <input ref={galleryRef} type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm,.mov" multiple hidden onChange={(event) => void addFiles(event.target.files)} />
    {cameraOpen && <div className="media-video-recorder">
      <video ref={previewRef} autoPlay muted playsInline aria-label="Pratinjau kamera video" />
      <div className="media-video-recorder__controls">
        <span>{recording ? `Merekam ${String(Math.floor(recordingSeconds / 60)).padStart(2, '0')}:${String(recordingSeconds % 60).padStart(2, '0')} · maksimal 00:45` : 'Kamera belakang siap'}</span>
        {!recording ? <button type="button" className="primary-button" onClick={startRecording}><Video aria-hidden="true" />Mulai Rekam</button> : <button type="button" className="primary-button" onClick={finishRecording}><CircleStop aria-hidden="true" />Simpan Video</button>}
        <button type="button" className="secondary-action" onClick={cancelRecording} disabled={working}><X aria-hidden="true" />Batal</button>
      </div>
    </div>}
    <p className="media-attachment-hint">Format foto JPG, PNG, WEBP; video MP4, MOV, WEBM. Maksimal {maxAttachments} file dan total 18 MB. Foto akan disiapkan otomatis.</p>
    {working && <p className="media-attachment-status" role="status">{status || 'Menyiapkan file...'}</p>}
    {!working && status && <p className="media-attachment-status" role="status">{status}</p>}
    {error && <p className="form-inline-error" role="alert">{error}</p>}
    {files.length > 0 && <ul className="media-attachment-files">{files.map((file, index) => <li key={`${file.name}-${index}`}><span>{file.name} · {(file.size / 1024 / 1024).toFixed(1)} MB</span><button type="button" aria-label={`Hapus ${file.name}`} onClick={() => { onChange(files.filter((_, itemIndex) => itemIndex !== index)); setStatus(''); }} disabled={disabled || working}><X aria-hidden="true" /></button></li>)}</ul>}
  </div>;
}
