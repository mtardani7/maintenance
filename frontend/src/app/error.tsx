'use client';

import { ErrorState } from '@/components/ui';

export default function GlobalError({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return <ErrorState description="Ruang kerja tidak dapat dimuat. Coba muat ulang halaman ini." onRetry={reset} />;
}
