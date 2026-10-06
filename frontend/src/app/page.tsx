import Image from 'next/image';
import Link from 'next/link';
import {
	ArrowDown,
	ArrowRight,
	Bell,
	Check,
	ClipboardList,
	Factory,
	QrCode,
	Settings2,
	ShieldCheck,
	Smartphone,
	TicketCheck,
	Wrench,
} from 'lucide-react';

const steps = [
	{ title: 'Scan QR Mesin', description: 'Scan QR pada mesin untuk langsung mengenali mesin yang bermasalah.', icon: QrCode },
	{ title: 'Laporkan Masalah', description: 'Pilih jenis masalah dan ceritakan kondisi mesin dengan jelas.', icon: ClipboardList },
	{ title: 'Maintenance Menerima Tiket', description: 'Laporan diteruskan menjadi tiket untuk ditangani tim Maintenance.', icon: Bell },
	{ title: 'Perbaikan Mesin', description: 'Maintenance mencatat penyebab, tindakan, dan hasil perbaikan.', icon: Wrench },
	{ title: 'Masalah Selesai', description: 'Tiket ditutup dan riwayat perbaikan tersimpan di aplikasi.', icon: Check },
];

const flow = ['Operator', 'Lapor Masalah', 'Ticket Maintenance', 'Perbaikan', 'Selesai'];
const basePath = process.env.NEXT_PUBLIC_BASE_PATH ?? '';

function Brand() {
	return (
		<Link className="landing-brand" href="/" aria-label="MIRA beranda">
			<Image src={`${basePath}/qa-logo.png`} alt="" width={40} height={40} priority unoptimized />
			<span><strong>MIRA</strong><small>Maintenance Improvement Report Analysis</small></span>
		</Link>
	);
}

function StepIcon({ icon: Icon }: { icon: typeof QrCode }) {
	return <div className="landing-step-icon"><Icon size={25} strokeWidth={1.8} aria-hidden="true" /></div>;
}

export default function Home() {
	return (
		<main className="landing-page">
			<header className="landing-header">
				<Brand />
				<nav aria-label="Navigasi panduan" className="landing-nav">
					<a href="#cara-menggunakan">Cara Menggunakan</a>
					<a href="#alur-maintenance">Alur Maintenance</a>
					<Link className="landing-nav-login" href="/login">Masuk <ArrowRight size={15} /></Link>
				</nav>
			</header>

			<section className="landing-hero" aria-labelledby="landing-title">
				<div className="landing-hero-copy">
					<p className="landing-eyebrow"><span /> Panduan penggunaan MIRA</p>
					<h1 id="landing-title">Laporkan Masalah Mesin dengan Mudah</h1>
					<p className="landing-lead">Scan QR mesin, buat laporan, dan pantau proses perbaikannya dalam satu aplikasi.</p>
					<div className="landing-hero-actions">
						<Link className="landing-button landing-button-primary" href="/login">Mulai Sekarang <ArrowRight size={17} /></Link>
						<a className="landing-button landing-button-secondary" href="#cara-menggunakan">Cara Menggunakan <ArrowDown size={16} /></a>
					</div>
					<div className="landing-hero-note"><ShieldCheck size={17} /> Laporan dan riwayat pekerjaan tercatat rapi.</div>
				</div>
				<div className="landing-hero-art" aria-hidden="true">
					<div className="landing-art-orbit landing-art-orbit-one" />
					<div className="landing-art-orbit landing-art-orbit-two" />
					<div className="landing-art-machine"><Factory size={84} strokeWidth={1.25} /><span className="landing-art-machine-light" /><span className="landing-art-machine-tag"><QrCode size={22} /></span></div>
					<div className="landing-art-phone"><div className="landing-phone-speaker" /><Smartphone className="landing-phone-outline" size={155} strokeWidth={1.1} /><div className="landing-phone-screen"><span className="landing-phone-label">LAPORAN BARU</span><strong>Mesin berhenti</strong><small>Plant 3 · Breyer 1</small><span className="landing-phone-status"><span /> Siap dikirim</span></div></div>
					<div className="landing-art-bubble landing-art-bubble-check"><Check size={17} /></div>
					<div className="landing-art-bubble landing-art-bubble-wrench"><Wrench size={17} /></div>
				</div>
			</section>

			<section className="landing-section landing-steps-section" id="cara-menggunakan">
				<div className="landing-section-heading"><p className="landing-eyebrow">Mudah dari awal sampai selesai</p><h2>Cara Menggunakan Aplikasi</h2><p>Ikuti lima langkah sederhana untuk memastikan masalah mesin ditangani dan tercatat.</p></div>
				<div className="landing-step-grid">
					{steps.map((step, index) => <article className="landing-step-card" key={step.title}>
						<div className="landing-step-top"><StepIcon icon={step.icon} /><span className="landing-step-number">0{index + 1}</span></div>
						<h3>{step.title}</h3><p>{step.description}</p>
					</article>)}
				</div>
			</section>

			<section className="landing-section landing-flow-section" id="alur-maintenance">
				<div className="landing-section-heading landing-section-heading-inline"><div><p className="landing-eyebrow">Dari laporan menjadi tindakan</p><h2>Alur Maintenance</h2></div><p>Setiap laporan bergerak melalui proses yang jelas hingga masalah selesai.</p></div>
				<ol className="landing-flow">
					{flow.map((item, index) => <li key={item}><span className="landing-flow-dot">{index === flow.length - 1 ? <Check size={19} /> : index === 2 ? <TicketCheck size={19} /> : index === 3 ? <Wrench size={19} /> : index === 0 ? <Smartphone size={19} /> : <ClipboardList size={19} />}</span><span className="landing-flow-label">{item}</span>{index < flow.length - 1 && <ArrowRight className="landing-flow-arrow" size={18} />}</li>)}
				</ol>
			</section>

			<section className="landing-section landing-roles-section">
				<div className="landing-section-heading"><p className="landing-eyebrow">Satu aplikasi, peran yang saling terhubung</p><h2>Siapa yang Menggunakan Aplikasi?</h2><p>Setiap orang mendapat langkah kerja yang sesuai dengan tanggung jawabnya.</p></div>
				<div className="landing-role-grid">
					<article className="landing-role-card"><span className="landing-role-icon"><Smartphone size={23} /></span><h3>Operator</h3><p>Melaporkan masalah mesin dan melihat perkembangan laporan.</p><span className="landing-role-flow">Scan QR <ArrowRight size={14} /> Laporkan</span></article>
					<article className="landing-role-card"><span className="landing-role-icon"><Wrench size={23} /></span><h3>Maintenance</h3><p>Menangani tiket, melakukan perbaikan, dan mencatat hasil pekerjaan.</p><span className="landing-role-flow">Terima <ArrowRight size={14} /> Kerjakan <ArrowRight size={14} /> Selesaikan</span></article>
					<article className="landing-role-card"><span className="landing-role-icon"><Settings2 size={23} /></span><h3>Admin</h3><p>Mengelola pengguna, plant, mesin, dan data pendukung sistem.</p><span className="landing-role-flow">Kelola Sistem <ArrowRight size={14} /></span></article>
				</div>
			</section>

			<section className="landing-section landing-guide-section">
				<div className="landing-guide-copy"><p className="landing-eyebrow">Panduan Operator</p><h2>Cara Operator Melapor</h2><p>Gunakan QR pada mesin agar laporan langsung terhubung ke mesin yang benar.</p>
					<ol className="landing-guide-list"><li><span>1</span>Buka aplikasi</li><li><span>2</span>Tekan tombol “+”</li><li><span>3</span>Pilih “Scan QR Mesin”</li><li><span>4</span>Scan QR pada mesin</li><li><span>5</span>Isi masalah yang ditemukan</li><li><span>6</span>Kirim laporan</li></ol>
				</div>
				<div className="landing-guide-visual landing-operator-visual" aria-label="Ilustrasi langkah operator membuat laporan">
					<div className="landing-guide-phone"><span className="landing-phone-camera" /><div className="landing-phone-appbar"><Image src={`${basePath}/qa-logo.png`} alt="" width={23} height={23} unoptimized /><span>MIRA</span><Bell size={15} /></div><div className="landing-phone-welcome">Halo, Operator</div><div className="landing-phone-scan"><QrCode size={44} /><span>Scan QR Mesin</span></div><div className="landing-phone-report"><span>Laporan Terbaru</span><strong>Mesin berhenti</strong><small>Menunggu Maintenance</small></div><span className="landing-phone-add">+</span></div>
					<div className="landing-guide-caption"><QrCode size={18} /><span><strong>Scan dan laporkan</strong><small>Informasi mesin terisi lebih mudah</small></span></div>
				</div>
			</section>

			<section className="landing-section landing-guide-section landing-maintenance-section">
				<div className="landing-guide-visual landing-maintenance-visual" aria-label="Ilustrasi tiket Maintenance dan hasil pekerjaan">
					<div className="landing-ticket-illustration"><div className="landing-ticket-head"><span><TicketCheck size={18} /> Tiket Maintenance</span><b>OPEN</b></div><small>RX03-290926-M01-001</small><h3>Mesin berhenti</h3><div className="landing-ticket-machine"><Factory size={16} /> Plant 3 · Breyer 1</div><div className="landing-ticket-divider" /><div className="landing-ticket-progress"><span><i /> Tindakan</span><span><i /> Hasil pekerjaan</span><span><i /> Selesai</span></div><div className="landing-ticket-work"><Wrench size={17} /><span><b>Catat hasil pekerjaan</b><small>Penyebab · Tindakan · Solusi</small></span></div></div>
					<div className="landing-guide-caption"><Wrench size={18} /><span><strong>Kerjakan dan catat</strong><small>Semua hasil menjadi riwayat tiket</small></span></div>
				</div>
				<div className="landing-guide-copy"><p className="landing-eyebrow">Panduan Maintenance</p><h2>Cara Maintenance Menangani Masalah</h2><p>Kerjakan tiket yang masuk dan catat hasilnya agar Operator dapat melihat perkembangan.</p>
					<ol className="landing-maintenance-flow"><li><span>01</span><strong>Tiket Baru</strong></li><li><span>02</span><strong>Buka Ticket</strong></li><li><span>03</span><strong>Kerjakan</strong></li><li><span>04</span><strong>Catat Hasil</strong></li><li><span>05</span><strong>Selesaikan Ticket</strong></li></ol>
				</div>
			</section>

			<section className="landing-notification-section">
				<div className="landing-notification-icon"><Bell size={25} /></div>
				<div className="landing-notification-copy"><p className="landing-eyebrow">Proses selalu terpantau</p><h2>Selalu Mendapatkan Informasi</h2><p>Pemberitahuan membantu setiap peran mengetahui saat ada pekerjaan yang perlu diperhatikan.</p></div>
				<div className="landing-notification-flow"><div><span className="landing-notification-symbol"><TicketCheck size={18} /></span><span><small>Saat tiket baru</small><strong>Maintenance mendapat notifikasi</strong></span></div><ArrowDown size={17} /><div><span className="landing-notification-symbol"><Check size={18} /></span><span><small>Saat tiket selesai</small><strong>Operator mendapat notifikasi</strong></span></div></div>
			</section>

			<footer className="landing-footer"><Brand /><span>Pelaporan masalah dan riwayat perbaikan mesin dalam satu aplikasi.</span><Link href="/login" className="landing-footer-link">Masuk ke MIRA <ArrowRight size={15} /></Link></footer>
		</main>
	);
}
