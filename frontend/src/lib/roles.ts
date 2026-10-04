import type { Role } from './types';

export const roles: { id: Role; label: string; scope: string }[] = [
  { id: 'operator', label: 'Operator', scope: 'Pantau status pabrik dan laporkan insiden' },
  { id: 'technician', label: 'Teknisi', scope: 'Laksanakan pekerjaan pemeliharaan yang ditugaskan' },
  { id: 'supervisor', label: 'Supervisor', scope: 'Koordinasikan tim dan prioritas' },
  { id: 'qa', label: 'QA', scope: 'Tinjau mutu dan bukti penyelesaian' },
  { id: 'admin', label: 'Admin', scope: 'Kelola pengguna dan konfigurasi sistem' },
];

export const routeAccess: Record<string, Role[]> = {
  '/dashboard': ['operator', 'technician', 'supervisor', 'qa', 'admin'],
  '/machines': ['operator', 'technician', 'supervisor', 'qa', 'admin'],
  '/incidents': ['operator', 'technician', 'supervisor', 'qa', 'admin'],
  '/tickets': ['operator', 'technician', 'supervisor', 'qa', 'admin'],
  '/notifications': ['operator', 'technician', 'supervisor', 'qa', 'admin'],
  '/qa': ['qa', 'admin'],
  '/supervisor': ['supervisor', 'admin'],
  '/reports': ['supervisor', 'qa', 'admin'],
  '/plants': ['admin'],
  '/settings': ['admin'],
  '/users': ['admin'],
};
