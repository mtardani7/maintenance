import type { Machine } from './maintenance-types';
import type { Ticket } from './ticket-types';

export type QADefectSeverity = 'CRITICAL' | 'HIGH' | 'MEDIUM' | 'LOW';
export type QAMaintenanceFilter = 'all' | 'has-ticket' | 'no-ticket' | 'open' | 'in-progress' | 'resolved' | 'closed';

export type QADefect = {
  id: number | string;
  defectId: string;
  machine: Pick<Machine, 'id' | 'code' | 'name'>;
  plant: string;
  defectType: string;
  severity: QADefectSeverity;
  description: string;
  inspectionDate: string;
  quantity: number;
  inspector: { id: number | string; name: string };
  maintenanceTicket?: Pick<Ticket, 'id' | 'number' | 'status'>;
};

export type QADefectPage = {
  data: QADefect[];
  currentPage: number;
  lastPage: number;
  total: number;
};
