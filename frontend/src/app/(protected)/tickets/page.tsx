import { TicketList } from '@/components/ticket-list';
import { SectionHeading } from '@/components/ui';

export default function TicketsPage() {
	return <>
		<div className="ticket-page-heading"><SectionHeading eyebrow="Manajemen pekerjaan" title="Tiket Pemeliharaan" description="Daftar pekerjaan maintenance yang perlu ditangani." /></div>
		<TicketList />
	</>;
}
