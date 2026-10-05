export type NotificationType = 'ticket_created' | 'ticket_closed' | 'TICKET_CREATED' | 'TICKET_ASSIGNED' | 'TICKET_STATUS_CHANGED' | 'TICKET_OVERDUE' | 'TICKET_RESOLVED' | 'TICKET_REOPENED' | string;

export type AppNotification = {
  id: number | string;
  type: NotificationType;
  title: string;
  body: string;
  createdAt: string;
  read: boolean;
  relatedTicketId?: number | string;
  ticketNumber?: string;
  machine?: string;
  problem?: string;
  solution?: string;
  closedBy?: string;
  closedAt?: string;
  incidentId?: number | string;
  url?: string;
};

export type NotificationPage = {
  data: AppNotification[];
  unreadCount: number;
  currentPage: number;
  lastPage: number;
};
