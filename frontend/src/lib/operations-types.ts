import type { TicketPriority, TicketStatus } from './ticket-types';

export type OperationsFilters = {
  plant?: string;
  priority?: TicketPriority | '';
  status?: TicketStatus | '';
  date?: string;
};

export type OperationalStats = {
  open: number;
  unassigned: number;
  assigned: number;
  inProgress: number;
  overdue: number;
  resolvedToday: number;
  closedToday: number;
};

export type AttentionTicket = {
  id: number | string;
  number: string;
  machine: string;
  problem: string;
  priority: TicketPriority;
  status: TicketStatus;
  sla?: { status: 'ON_TRACK' | 'DUE_SOON' | 'OVERDUE'; targetAt?: string; remaining?: string; overdueDuration?: string };
};

export type TechnicianWorkload = {
  technicianId: number | string;
  technician: string;
  assigned: number;
  inProgress: number;
  overdue: number;
  resolved: number;
};

export type SupervisorOverview = {
  unassigned: AttentionTicket[];
  critical: AttentionTicket[];
  overdue: AttentionTicket[];
  workload: TechnicianWorkload[];
  repeatedFailures: { machineId: number | string; machine: string; count: number }[];
};

export type ReportMetrics = {
  mttr?: string;
  mtbf?: string;
  totalDowntime?: string;
  responseTime?: string;
  slaCompliance?: string;
  ticketVolume?: number;
  repeatFailures?: number;
  maintenanceByPlant?: { plant: string; total: number }[];
  topProblematicMachines?: { machine: string; total: number }[];
  topFailureTypes?: { type: string; total: number }[];
};

export type ReportCategory = 'summary' | 'tickets' | 'incidents' | 'machines' | 'spare-parts' | 'downtime';
export type ReportFilters = {
  start_date: string;
  end_date: string;
  plant_id?: string;
  machine_id?: string;
  category: ReportCategory;
  page?: number;
  per_page?: number;
  search?: string;
  sort?: 'newest' | 'oldest' | 'incidents_desc' | 'incidents_asc';
};
export type ReportRowPage<T = Record<string, unknown>> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number };
export type MaintenanceReport = {
  filters: Pick<ReportFilters, 'start_date' | 'end_date' | 'plant_id' | 'machine_id'>;
  summary: { tickets_created: number; tickets_open_now: number; tickets_closed: number; incidents: number };
  trend: Array<{ period: string; created: number; closed: number }>;
  incident_trend: Array<{ period: string; total: number }>;
  trend_granularity: 'day' | 'month';
  ticket_statuses: Array<{ status: string; total: number }>;
  incident_types: Array<{ problem_type: string; total: number }>;
  top_incident_machines: Array<{ machine_id: number; code: string; name: string; plant: string | null; total: number }>;
  incidents_by_plant: Array<{ plant_id: number | null; code: string | null; name: string | null; total: number }>;
  machine_count_by_plant: Array<{ plant_id: number | null; plant: string | null; plant_code: string | null; total: number }>;
  machines: ReportRowPage | null;
  spare_parts: { transactions: number; quantity: number; top: Array<{ name: string; material_code: string; transactions: number; quantity: number }> };
  rows: ReportRowPage | null;
  downtime: {
    available: boolean;
    total_hours: number;
    work_count: number;
    average_hours: number;
    top_machine: { machine_id: number; code: string; name: string; total_hours: number } | null;
    by_machine: { machine_id: number; code: string; name: string; total_hours: number; work_count: number }[];
    by_plant: { plant_id: number | null; code: string | null; name: string | null; total_hours: number; work_count: number }[];
    trend: { period: string; total_hours: number }[];
    trend_granularity?: 'day' | 'month';
  };
};

export type MachineAnalytics = {
  totalIncidents?: number;
  maintenanceTickets?: number;
  openTickets?: number;
  repeatFailures?: number;
  totalDowntime?: string;
  latestMaintenance?: string;
};
