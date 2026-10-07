export const MAX_ATTACHMENTS = 5;
export const MAX_ATTACHMENT_BYTES = 18 * 1024 * 1024;
export const MAX_TOTAL_ATTACHMENT_BYTES = 18 * 1024 * 1024;
const IMAGE_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);
const VIDEO_EXTENSIONS = new Set(['mp4', 'mov', 'webm']);

function extension(file: File) {
  return file.name.split('.').pop()?.toLowerCase() ?? '';
}

export function isSupportedMedia(file: File): boolean {
  const ext = extension(file);
  return IMAGE_TYPES.has(file.type.toLowerCase()) || (file.type === 'image/jpg') ||
    (['jpg', 'jpeg', 'png', 'webp'].includes(ext) && (!file.type || file.type.startsWith('image/'))) ||
    (VIDEO_EXTENSIONS.has(ext) && (!file.type || file.type.startsWith('video/')));
}

function loadImage(file: File): Promise<{ image: CanvasImageSource; width: number; height: number; release: () => void }> {
  if ('createImageBitmap' in window) {
    return createImageBitmap(file).then((bitmap) => ({ image: bitmap, width: bitmap.width, height: bitmap.height, release: () => bitmap.close() }));
  }
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const image = new Image();
    image.onload = () => resolve({ image, width: image.naturalWidth, height: image.naturalHeight, release: () => URL.revokeObjectURL(url) });
    image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Foto tidak dapat dibaca pada perangkat ini.')); };
    image.src = url;
  });
}

async function optimizePhoto(file: File): Promise<File> {
  let loaded: Awaited<ReturnType<typeof loadImage>> | undefined;
  try {
    loaded = await loadImage(file);
    const ratio = Math.min(1, 1920 / Math.max(loaded.width, loaded.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(loaded.width * ratio));
    canvas.height = Math.max(1, Math.round(loaded.height * ratio));
    const context = canvas.getContext('2d');
    if (!context) return file;
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(loaded.image, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.82));
    canvas.width = 0;
    canvas.height = 0;
    if (!blob) return file;
    if (blob.size > file.size * 1.2 && file.size <= MAX_ATTACHMENT_BYTES) return file;
    const baseName = file.name.replace(/\.[^.]+$/, '') || 'foto';
    return new File([blob], `${baseName}.jpg`, { type: 'image/jpeg', lastModified: Date.now() });
  } catch {
    return file;
  } finally {
    loaded?.release();
  }
}

export async function prepareMedia(file: File): Promise<File> {
  if (!isSupportedMedia(file)) throw new Error(`${file.name}: jenis file tidak didukung.`);
  const isImage = file.type.startsWith('image/') || ['jpg', 'jpeg', 'png', 'webp'].includes(extension(file));
  if (file.size > (isImage ? 50 * 1024 * 1024 : MAX_ATTACHMENT_BYTES)) throw new Error(`${file.name}: ukuran file terlalu besar.`);
  const result = isImage ? await optimizePhoto(file) : file;
  if (result.size > MAX_ATTACHMENT_BYTES) throw new Error(`${file.name}: ukuran file setelah disiapkan melebihi 18 MB.`);
  return result;
}

export function validateAttachmentSelection(files: File[]): string | null {
  if (files.length > MAX_ATTACHMENTS) return 'Maksimal 5 lampiran.';
  if (files.some((file) => !isSupportedMedia(file))) return 'Gunakan foto JPG, PNG, WEBP atau video MP4, MOV, WEBM.';
  if (files.some((file) => file.size > MAX_ATTACHMENT_BYTES)) return 'Ukuran setiap lampiran maksimal 18 MB.';
  if (files.reduce((sum, file) => sum + file.size, 0) > MAX_TOTAL_ATTACHMENT_BYTES) return 'Total ukuran lampiran maksimal 18 MB.';
  return null;
}
