export type TicketStatus =
  "OPEN" | "CLOSED";
export type TicketPriority = "CRITICAL" | "HIGH" | "MEDIUM" | "LOW";

export type Ticket = {
  id: number | string;
  number: string;
  machine: { id: number | string; code: string; name: string };
  plant: string;
  location: string;
  problemType: string;
  description: string;
  priority: TicketPriority;
  status: TicketStatus;
  durationHours?: number;
  solution?: string;
  reason?: string;
  actionTaken?: string;
  executor?: { id: number | string; name: string; role?: string };
  rootCauseAnalysis?: string;
  correctiveActionPlan?: string;
  targetAt?: string;
  actionBy?: { id: number | string; name: string; role?: string };
  closed_at?: string;
  closed_by?: { id: number | string; name: string; role?: string };
  verificationChecklist?: VerificationChecklist;
  spareParts: SparePart[];
  sourceType?: "OPERATOR" | "QA" | "MANUAL";
  sourceId?: string;
  reporter?: { id: number | string; name: string };
  technician?: { id: number | string; name: string };
  createdAt: string;
  sla?: {
    status: "ON_TRACK" | "DUE_SOON" | "OVERDUE";
    dueAt?: string;
    targetAt?: string;
    remaining?: string;
    overdueDuration?: string;
  };
  attachments?: { id: number | string; name: string; url?: string }[];
  timeline?: TimelineEvent[];
};

export type TimelineEvent = {
  id: number | string;
  status: TicketStatus;
  label?: string;
  actor?: string;
  action?: string;
  change?: string;
  note?: string;
  createdAt: string;
};

export type TicketFilters = {
  search?: string;
  status?: TicketStatus | "";
  priority?: TicketPriority | "";
  plant?: string;
  technician?: string;
  sort?: "newest" | "oldest";
  page?: number;
  perPage?: number;
};

export type TicketPage = {
  data: Ticket[];
  currentPage: number;
  lastPage: number;
  total: number;
};

export type TicketAction =
  "close";

export type TicketActionInput = {
  reason: string;
  actionTaken: string;
  durationHours?: number;
  solution?: string;
};

export type MaintenanceUser = { id: number | string; name: string; role: string };

export type BreakdownAnalysisInput = {
  rootCauseAnalysis?: string;
  correctiveActionPlan?: string;
  targetAt?: string;
  actionById?: number | string;
};

export type VerificationValue = "OK" | "NOK" | "N/A";
export type VerificationKey = "machine_cleanliness" | "water" | "grease" | "gram" | "machine_function" | "machine_safety" | "tool";
export type VerificationChecklist = Partial<Record<VerificationKey, VerificationValue>>;

export type SparePart = {
  id: number | string;
  name: string;
  materialCode: string;
  quantity: number;
  remark?: string;
};

export const ticketStatuses: TicketStatus[] = ["OPEN", "CLOSED"];
export const ticketPriorities: TicketPriority[] = [
  "CRITICAL",
  "HIGH",
  "MEDIUM",
  "LOW",
];
