import { SectionHeading } from '@/components/ui';
import { EmailAutomationSettings } from '@/components/email-automation-settings';

export default function SettingsPage() {
	return <div className="settings-page"><SectionHeading eyebrow="Konfigurasi" title="Pengaturan email" description="Atur penerima notifikasi tiket dan jadwal laporan pemeliharaan." /><EmailAutomationSettings /></div>;
}
