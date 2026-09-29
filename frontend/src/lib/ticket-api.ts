import { apiRequest, ApiConfigurationError, ApiError } from "./api";
import type {
  MaintenanceUser,
  BreakdownAnalysisInput,
  VerificationChecklist,
  SparePart,
  Ticket,
  TicketAction,
  TicketActionInput,
  TicketFilters,
  TicketPage,
} from "./ticket-types";

type TicketCollection =
  | Ticket[]
  | {
      data: Ticket[];
      meta?: { current_page?: number; last_page?: number; total?: number };
    };

const paths = {
  list: process.env.NEXT_PUBLIC_TICKETS_PATH || "/tickets",
  detail:
    process.env.NEXT_PUBLIC_TICKET_DETAIL_PATH_TEMPLATE || "/tickets/{id}",
  action:
    process.env.NEXT_PUBLIC_TICKET_ACTION_PATH_TEMPLATE ||
    "/tickets/{id}/actions/{action}",
  stats: process.env.NEXT_PUBLIC_TICKET_STATS_PATH,
};

export type TicketStats = {
  open: number;
  unassigned: number;
  assigned: number;
  inProgress: number;
  overdue: number;
  resolvedToday: number;
};

export function ticketApiAvailability() {
  return Boolean(paths.list);
}

function requiredPath(value: string | undefined, label: string) {
  if (!value)
    throw new ApiConfigurationError(`${label} endpoint is not configured yet.`);
  return value;
}

function withQuery(path: string, filters: TicketFilters) {
  const query = new URLSearchParams();
  if (filters.search) query.set("search", filters.search);
  if (filters.status) query.set("status", filters.status);
  if (filters.priority) query.set("priority", filters.priority);
  if (filters.plant) query.set("plant_id", filters.plant);
  if (filters.technician) query.set("technician", filters.technician);
  if (filters.sort) query.set("sort", filters.sort);
  if (filters.page) query.set("page", String(filters.page));
  query.set("per_page", String(filters.perPage ?? 10));
  const suffix = query.toString();
  return suffix ? `${path}${path.includes("?") ? "&" : "?"}${suffix}` : path;
}

function replacePath(
  template: string | undefined,
  id: Ticket["id"],
  label: string,
  action?: TicketAction,
) {
  return requiredPath(template, label)
    .replace("{id}", encodeURIComponent(String(id)))
    .replace("{action}", action ?? "");
}

function normalizeTicket(
  ticket: Partial<Ticket> & Record<string, unknown>,
): Ticket {
  const machineId = (ticket.machine_id ??
    (ticket.machine as { id?: number | string } | undefined)?.id ??
    "-") as number | string;
  const machine = ticket.machine as
    { id?: number | string; code?: string; name?: string; plant?: { id?: number | string; code?: string; name?: string } } | undefined;
  const plant = ticket.plant as
    { id?: number | string; code?: string; name?: string } | undefined;
  const plantName = plant?.name ?? machine?.plant?.name;
  const plantCode = plant?.code ?? machine?.plant?.code;
  const ticketNumber = ticket.ticket_number ?? ticket.number;
  const normalizedNumber = typeof ticketNumber === "string" || typeof ticketNumber === "number"
    ? String(ticketNumber)
    : `TKT-${String(ticket.id ?? "-")}`;

  return {
    id: ticket.id ?? "-",
    number: normalizedNumber,
    machine: {
      id: machine?.id ?? machineId,
      code: machine?.code ?? `Mesin #${machineId}`,
      name: machine?.name ?? `Mesin #${machineId}`,
    },
    plant: plantName ?? plantCode ?? (ticket.plant_name as string | undefined) ?? (ticket.plant_id ? `Plant #${ticket.plant_id}` : "-"),
    location: String(ticket.location ?? "-"),
    problemType: String(ticket.problemType ?? ticket.problem_type ?? "Lainnya"),
    description: String(ticket.description ?? ""),
    priority: (ticket.priority ?? "MEDIUM") as Ticket["priority"],
    status: (ticket.status ?? "OPEN") as Ticket["status"],
    durationHours:
      ticket.durationHours ?? (ticket.duration_hours as number | undefined),
    solution: ticket.solution as string | undefined,
    reason: ticket.reason as string | undefined,
    actionTaken: (ticket.actionTaken ?? ticket.action_taken) as string | undefined,
    executor: ticket.executor as Ticket["executor"],
    rootCauseAnalysis: (ticket.rootCauseAnalysis ?? ticket.root_cause_analysis) as string | undefined,
    correctiveActionPlan: (ticket.correctiveActionPlan ?? ticket.corrective_action_plan) as string | undefined,
    targetAt: (ticket.targetAt ?? ticket.target_at) as string | undefined,
    actionBy: ticket.actionBy ?? ticket.action_by as Ticket["actionBy"],
    closedAt: (ticket.closedAt ?? ticket.closed_at) as string | undefined,
    closedBy: ticket.closedBy ?? ticket.closed_by as Ticket["closedBy"],
    verificationChecklist: (ticket.verificationChecklist ?? ticket.verification_checklist) as VerificationChecklist | undefined,
    spareParts: ((ticket.spareParts ?? ticket.spare_parts) as Array<Record<string, unknown>> | undefined ?? []).map((part) => ({
      id: (part.id as number | string | undefined) ?? "-",
      name: String(part.name ?? ""),
      materialCode: String(part.materialCode ?? part.material_code ?? ""),
      quantity: Number(part.quantity ?? 0),
      remark: part.remark as string | undefined,
    })),
    sourceType: (ticket.sourceType ?? ticket.source) as Ticket["sourceType"],
    reporter: ticket.reporter as Ticket["reporter"],
    createdAt: String(ticket.createdAt ?? ticket.created_at ?? ""),
  };
}

export async function getTickets(
  filters: TicketFilters = {},
): Promise<TicketPage> {
  const response = await apiRequest<TicketCollection>(
    withQuery(requiredPath(paths.list, "Ticket list"), filters),
  );
  if (Array.isArray(response))
    return {
      data: response.map((ticket) =>
        normalizeTicket(ticket as Partial<Ticket> & Record<string, unknown>),
      ),
      currentPage: filters.page ?? 1,
      lastPage: 1,
      total: response.length,
    };
  return {
    data: response.data.map((ticket) =>
      normalizeTicket(ticket as Partial<Ticket> & Record<string, unknown>),
    ),
    currentPage: response.meta?.current_page ?? filters.page ?? 1,
    lastPage: response.meta?.last_page ?? 1,
    total: response.meta?.total ?? response.data.length,
  };
}

export function getTicket(id: Ticket["id"]) {
  return apiRequest<Partial<Ticket> & Record<string, unknown>>(
    replacePath(paths.detail, id, "Ticket detail"),
  ).then((ticket) => normalizeTicket(ticket));
}

export function getTicketStats() {
  return apiRequest<TicketStats>(
    requiredPath(paths.stats, "Ticket statistics"),
  );
}

export function performTicketAction(
  id: Ticket["id"],
  action: TicketAction,
  input: Partial<TicketActionInput> = {},
) {
  const { durationHours, solution } = input;
  const body = {
    action,
    reason: input.reason,
    action_taken: input.actionTaken,
    executor_id: input.executorId,
    ...(durationHours ? { duration_hours: durationHours } : {}),
    ...(solution?.trim() ? { solution: solution.trim() } : {}),
  };
  return apiRequest<Partial<Ticket> & Record<string, unknown>>(
    replacePath(paths.action, id, "Ticket actions", action),
    { method: "POST", body: JSON.stringify(body) },
  ).then((ticket) => normalizeTicket(ticket));
}

export function getMaintenanceUsers() {
  return apiRequest<MaintenanceUser[]>('/maintenance-users');
}

export function updateBreakdownAnalysis(ticketId: Ticket["id"], input: BreakdownAnalysisInput) {
  return apiRequest<Partial<Ticket> & Record<string, unknown>>(`/tickets/${encodeURIComponent(String(ticketId))}`, {
    method: "PUT",
    body: JSON.stringify({
      root_cause_analysis: input.rootCauseAnalysis,
      corrective_action_plan: input.correctiveActionPlan,
      target_at: input.targetAt || null,
      action_by_id: input.actionById || null,
    }),
  }).then((ticket) => normalizeTicket(ticket));
}

export function updateVerificationChecklist(ticketId: Ticket["id"], checklist: VerificationChecklist) {
  return apiRequest<Partial<Ticket> & Record<string, unknown>>(`/tickets/${encodeURIComponent(String(ticketId))}`, {
    method: "PUT",
    body: JSON.stringify({ verification_checklist: checklist }),
  }).then((ticket) => normalizeTicket(ticket));
}

export function addSparePart(ticketId: Ticket["id"], input: Omit<SparePart, "id">) {
  return apiRequest<Record<string, unknown>>(`/tickets/${encodeURIComponent(String(ticketId))}/spare-parts`, {
    method: "POST",
    body: JSON.stringify({
      name: input.name,
      material_code: input.materialCode,
      quantity: input.quantity,
      remark: input.remark,
    }),
  }).then((part) => ({
    id: (part.id as number | string | undefined) ?? "-",
    name: String(part.name ?? ""),
    materialCode: String(part.materialCode ?? part.material_code ?? ""),
    quantity: Number(part.quantity ?? 0),
    remark: part.remark as string | undefined,
  }));
}

export function removeSparePart(ticketId: Ticket["id"], sparePartId: SparePart["id"]) {
  return apiRequest<void>(`/tickets/${encodeURIComponent(String(ticketId))}/spare-parts/${encodeURIComponent(String(sparePartId))}`, { method: "DELETE" });
}

export function apiMessage(error: unknown) {
  if (error instanceof ApiError) {
    if (error.status === 401)
      return "Your session has expired. Sign in again to continue.";
    if (error.status === 403)
      return "You do not have permission to perform this action.";
    if (error.status === 404) return "This ticket could not be found.";
    if (error.status === 422)
      return error.message || "Check the required ticket fields and try again.";
    if (error.status >= 500)
      return "The maintenance service is having trouble. Try again shortly.";
  }
  if (error instanceof ApiConfigurationError) return error.message;
  if (error instanceof TypeError)
    return "The maintenance service could not be reached. Check your connection.";
  return "The request could not be completed.";
}
