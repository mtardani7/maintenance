import { apiRequest, ApiConfigurationError, ApiError } from './api';
import type { QADefect, QADefectPage, QAMaintenanceFilter } from './qa-types';
import type { Ticket } from './ticket-types';

type DefectResponse = QADefect[] | { data: QADefect[]; meta?: { current_page?: number; last_page?: number; total?: number } };

const paths = {
  list: process.env.NEXT_PUBLIC_QA_DEFECTS_PATH,
  detail: process.env.NEXT_PUBLIC_QA_DEFECT_DETAIL_PATH_TEMPLATE,
  relatedTicket: process.env.NEXT_PUBLIC_QA_DEFECT_TICKET_PATH_TEMPLATE,
  createTicket: process.env.NEXT_PUBLIC_TICKETS_CREATE_PATH,
  machineDefects: process.env.NEXT_PUBLIC_MACHINE_QA_DEFECTS_PATH_TEMPLATE,
};

function requiredPath(value: string | undefined, label: string) {
  if (!value) throw new ApiConfigurationError(`${label} endpoint is not configured yet.`);
  return value;
}

function defectPath(template: string | undefined, id: QADefect['id'], label: string) {
  return requiredPath(template, label).replace('{id}', encodeURIComponent(String(id)));
}

function normalize(response: DefectResponse, page: number): QADefectPage {
  if (Array.isArray(response)) return { data: response, currentPage: page, lastPage: 1, total: response.length };
  return { data: response.data, currentPage: response.meta?.current_page ?? page, lastPage: response.meta?.last_page ?? 1, total: response.meta?.total ?? response.data.length };
}

export async function getQADefects(page = 1, filter: QAMaintenanceFilter = 'all'): Promise<QADefectPage> {
  const path = requiredPath(paths.list, 'QA defect list');
  const query = new URLSearchParams({ page: String(page) });
  if (filter !== 'all') query.set('maintenance_status', filter);
  return normalize(await apiRequest<DefectResponse>(`${path}${path.includes('?') ? '&' : '?'}${query}`), page);
}

export function getQADefect(id: QADefect['id']) {
  return apiRequest<QADefect>(defectPath(paths.detail, id, 'QA defect detail'));
}

export function getQADefectMaintenanceTicket(id: QADefect['id']) {
  return apiRequest<Ticket | null>(defectPath(paths.relatedTicket, id, 'QA defect maintenance ticket'));
}

export function createMaintenanceTicketFromDefect(defect: QADefect) {
  return apiRequest<Ticket>(requiredPath(paths.createTicket, 'Ticket creation'), {
    method: 'POST',
    body: JSON.stringify({
      source_type: 'QA',
      source_id: defect.defectId,
      machine_id: defect.machine.id,
      plant: defect.plant,
      problem_type: defect.defectType,
      priority: defect.severity,
      description: defect.description,
    }),
  });
}

export function getMachineQADefects(machineId: string | number) {
  return apiRequest<QADefect[]>(defectPath(paths.machineDefects, machineId, 'Machine QA defects'));
}

export function qaApiMessage(error: unknown) {
  if (error instanceof ApiError) {
    if (error.status === 401) return 'Your session has expired. Sign in again to continue.';
    if (error.status === 403) return 'You do not have permission to view QA defects.';
    if (error.status === 404) return 'The QA defect could not be found.';
    if (error.status === 409) return 'A maintenance ticket already exists for this QA defect.';
    if (error.status === 422) return error.message || 'The QA defect data was not valid.';
    if (error.status >= 500) return 'The QA service is having trouble. Try again shortly.';
  }
  if (error instanceof ApiConfigurationError) return error.message;
  if (error instanceof TypeError) return 'The QA service could not be reached. Check your connection.';
  return 'The QA request could not be completed.';
}
