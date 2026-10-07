import { apiRequest, ApiConfigurationError } from './api';
import type { CreateIncidentInput, CreateTicketInput, Incident, Machine, MachineDetail, MachineFilters, MachinePage, MaintenanceTicket, MachineQADefect, Plant, ResolvedMachineQr } from './maintenance-types';
import { getMachineAnalytics } from './operations-api';

export type PlantOption = { id: number; code: string; name: string };
export type QaDashboard = {
  date: string;
  summary: { production_pcs: number; defect_qty: number; defect_rate: number; yield: number };
  pareto: Array<{ id: number; name: string; quantity: number; cumulative_percentage?: number }>;
  top_defects: Array<{ id: number; name: string; quantity: number }>;
  top_machines: Array<{ id: number; name: string; value: number; output_pcs?: number }>;
  defect_categories: Array<{ id: number; name: string; value: number }>;
  production_trend: Array<{ date: string; production_pcs: number }>;
  defect_trend: Array<{ date: string; defect_qty: number }>;
};

export type MaintenanceDashboard = {
  period_days: 7 | 30 | 90;
  starts_at: string;
  summary: { open_tickets: number; closed_today: number; overdue_tickets: number; incidents_today: number };
  needs_attention: Array<{
    ticket_number: string; problem_type: string; description: string; status: string; priority: string;
    created_at: string; machine_code?: string | null; machine_name?: string | null; plant_code?: string | null; plant_name?: string | null;
  }>;
  trend_machines: Array<{
    machine_id: number; machine_code: string; machine_name: string; plant_id: number | null; plant_code?: string | null;
    plant_name?: string | null; incident_count: number; last_occurred_at: string;
    problems: Array<{ problem_type: string; count: number; last_occurred_at: string }>;
  }>;
  repeated_problems: Array<{
    machine_id: number; machine_code: string; machine_name: string; problem_type: string; count: number; last_occurred_at: string;
  }>;
  recent_activity: Array<{
    id: number; problem_type: string; status: string; created_at: string; machine_code: string; machine_name: string; ticket_number?: string | null;
  }>;
};

type Collection<T> = T[] | { data: T[]; meta?: { current_page?: number; last_page?: number; total?: number } };

const paths = {
  machines: process.env.NEXT_PUBLIC_MACHINES_PATH ?? '/machines',
  machineDetail: process.env.NEXT_PUBLIC_MACHINE_DETAIL_PATH_TEMPLATE,
  machineIncidents: process.env.NEXT_PUBLIC_MACHINE_INCIDENTS_PATH_TEMPLATE,
  machineTickets: process.env.NEXT_PUBLIC_MACHINE_TICKETS_PATH_TEMPLATE,
    machineQaDefects: process.env.NEXT_PUBLIC_MACHINE_QA_DEFECTS_PATH_TEMPLATE,
  createIncident: process.env.NEXT_PUBLIC_INCIDENTS_CREATE_PATH ?? '/incidents',
  createTicket: process.env.NEXT_PUBLIC_TICKETS_CREATE_PATH ?? '/tickets',
};

function requiredPath(value: string | undefined, label: string): string {
  if (!value) throw new ApiConfigurationError(`${label} endpoint is not configured yet.`);
  return value;
}

function withQuery(path: string, filters: MachineFilters): string {
  const query = new URLSearchParams();
  if (filters.search) query.set('search', filters.search);
  if (filters.plant) query.set('plant', filters.plant);
  if (filters.status) query.set('status', filters.status);
  if (filters.page) query.set('page', String(filters.page));
  if (filters.per_page) query.set('per_page', String(filters.per_page));
  const suffix = query.toString();
  return suffix ? `${path}${path.includes('?') ? '&' : '?'}${suffix}` : path;
}

function collection<T>(response: Collection<T>): T[] {
  return Array.isArray(response) ? response : response.data;
}

function pathFor(template: string | undefined, id: Machine['id'], label: string): string {
  return requiredPath(template, label).replace('{id}', encodeURIComponent(String(id)));
}

export function maintenanceApiAvailability() {
  return Boolean(paths.machines);
}

export async function getMachines(filters: MachineFilters = {}): Promise<Machine[]> {
  return (await getMachinePage(filters)).data;
}

export async function getPlantOptions(): Promise<PlantOption[]> {
  const response = await apiRequest<{ data: PlantOption[] }>('/plants?is_active=true&per_page=100');
  return response.data;
}

export async function getPlants(filters: { search?: string; is_active?: string; page?: number; per_page?: number } = {}) {
  const query = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => { if (value !== undefined && value !== '') query.set(key, String(value)); });
  const response = await apiRequest<{ data: Plant[]; current_page: number; last_page: number; total: number }>(`/plants${query.toString() ? `?${query}` : ''}`);
  return response;
}

export async function createPlant(input: Pick<Plant, 'code' | 'name' | 'description' | 'is_active'>) {
  return apiRequest<Plant>('/plants', { method: 'POST', body: JSON.stringify(input) });
}

export async function updatePlant(id: Plant['id'], input: Partial<Pick<Plant, 'code' | 'name' | 'description' | 'is_active'>>) {
  return apiRequest<Plant>(`/plants/${id}`, { method: 'PUT', body: JSON.stringify(input) });
}

export async function deactivatePlant(id: Plant['id']) {
  return apiRequest<{ message: string }>(`/plants/${id}`, { method: 'DELETE' });
}

export async function getIncidents(): Promise<Incident[]> {
  const response = await apiRequest<{ data: Array<Incident & { ticket_number?: string }> }>('/incidents?per_page=100');
  return response.data.map((incident) => ({
    ...incident,
    ticketNumber: incident.ticket_number,
  }));
}

export async function getQaDashboard(filters: Record<string, string | number | undefined> = {}): Promise<QaDashboard> {
  const query = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => { if (value !== undefined && value !== '') query.set(key, String(value)); });
  const suffix = query.toString();
  return apiRequest<QaDashboard>(`/qa-dashboard${suffix ? `?${suffix}` : ''}`);
}

export async function getMaintenanceDashboard(filters: { period_days: 7 | 30 | 90; plant_id?: string }): Promise<MaintenanceDashboard> {
  const query = new URLSearchParams({ period_days: String(filters.period_days) });
  if (filters.plant_id) query.set('plant_id', filters.plant_id);
  return apiRequest<MaintenanceDashboard>(`/maintenance-dashboard?${query.toString()}`);
}

export async function getMachinePage(filters: MachineFilters = {}): Promise<MachinePage> {
  const response = await apiRequest<MachinePage | Collection<Machine>>(withQuery(requiredPath(paths.machines, 'Machine list'), filters));
  if (Array.isArray(response)) return { data: response, current_page: 1, last_page: 1, per_page: response.length, total: response.length };
  return response as MachinePage;
}

export async function requestMachine(input: Record<string, unknown>) {
  return apiRequest<Machine>(paths.machines ?? '/machines', { method: 'POST', body: JSON.stringify(input) });
}
export async function updateMachine(id: Machine['id'], input: Record<string, unknown>) {
  return apiRequest<Machine>(`/machines/${encodeURIComponent(String(id))}`, { method: 'PUT', body: JSON.stringify(input) });
}

export async function deleteMachine(id: Machine['id']) {
  return apiRequest<void>(`/machines/${encodeURIComponent(String(id))}`, { method: 'DELETE' });
}

export async function getMachineDetail(id: Machine['id']): Promise<MachineDetail> {
  const machine = await apiRequest<Machine>(pathFor(paths.machineDetail, id, 'Machine detail'));
  const [recentIncidents, openTickets] = await Promise.all([
    getMachineIncidents(id),
    getMachineTickets(id),
  ]);
  const qaDefects = paths.machineQaDefects ? await getMachineQADefects(id).catch(() => []) : undefined;
  const analytics = process.env.NEXT_PUBLIC_MACHINE_ANALYTICS_PATH_TEMPLATE ? await getMachineAnalytics(id).catch(() => undefined) : undefined;
  return { ...machine, recentIncidents, openTickets, qaDefects, analytics };
}

async function getMachineQADefects(id: Machine['id']) {
  const response = await apiRequest<Collection<MachineQADefect>>(pathFor(paths.machineQaDefects, id, 'Machine QA defects'));
  return collection(response);
}

export async function getMachineIncidents(id: Machine['id']): Promise<Incident[]> {
  const response = await apiRequest<Collection<Incident>>(pathFor(paths.machineIncidents, id, 'Machine incidents'));
  return collection(response);
}

export async function getMachineTickets(id: Machine['id']): Promise<MaintenanceTicket[]> {
  const response = await apiRequest<Collection<MaintenanceTicket>>(pathFor(paths.machineTickets, id, 'Machine tickets'));
  return collection(response);
}

export async function createIncident(input: CreateIncidentInput) {
  const values = {
      plant_id: input.plantId,
      machine_id: input.machineId,
      problem_type: input.problemType,
      description: input.description,
      action_taken: input.actionTaken,
      result: input.result,
      status: input.status,
      qr_payload: input.qrPayload,
  };
  let body: BodyInit;
  if (input.files?.length) {
    const data = new FormData();
    Object.entries(values).forEach(([key, value]) => { if (value !== undefined) data.append(key, String(value)); });
    input.files.forEach((file) => data.append('files[]', file, file.name));
    body = data;
  } else body = JSON.stringify(values);
  const response = await apiRequest<Incident & { ticket_number?: string }>(requiredPath(paths.createIncident, 'Incident creation'), { method: 'POST', body });
  return { ...response, ticketNumber: response.ticketNumber ?? response.ticket_number };
}

export function resolveMachineQr(payload: string) {
  return apiRequest<ResolvedMachineQr>('/machines/resolve-qr', { method: 'POST', body: JSON.stringify({ payload }) });
}

export async function createTicket(input: CreateTicketInput) {
  return apiRequest<MaintenanceTicket>(requiredPath(paths.createTicket, 'Ticket creation'), {
    method: 'POST',
    body: JSON.stringify({
      plant_id: input.plantId,
      machine_id: input.machineId,
      problem_type: input.problemType,
      description: input.description,
      source: input.source,
    }),
  });
}
