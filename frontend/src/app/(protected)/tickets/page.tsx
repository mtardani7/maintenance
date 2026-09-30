import { TicketList } from '@/components/ticket-list';
import { SectionHeading } from '@/components/ui';

export default function TicketsPage() {
	return <TicketList header={<SectionHeading eyebrow="Manajemen pekerjaan" title="Tiket Pemeliharaan" description="Daftar pekerjaan maintenance." />} />;
}
