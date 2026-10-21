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
	{ title: 'Pilih Mesin', description: 'Pindai kode QR (gambar kotak) pada mesin, atau pilih lokasi dan nama mesin di formulir.', icon: QrCode },
	{ title: 'Ceritakan Masalahnya', description: 'Pilih masalah yang paling mirip. Jika ragu, pilih “Lainnya”. Lalu tulis apa yang terjadi.', icon: ClipboardList },
	{ title: 'Laporan Diterima', description: 'Tim perbaikan akan melihat laporan Anda dan mulai memeriksa mesin.', icon: Bell },
	{ title: 'Mesin Diperiksa', description: 'Tim perbaikan memeriksa mesin, melakukan perbaikan, lalu mencatat hasilnya.', icon: Wrench },
	{ title: 'Lihat Hasilnya', description: 'Anda bisa melihat apakah laporan sedang diperiksa atau pekerjaan sudah selesai.', icon: Check },
];

const flow = ['Anda', 'Buat Laporan', 'Tim Perbaikan', 'Perbaikan', 'Selesai'];
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
					<a href="#alur-maintenance">Proses Penanganan</a>
					<Link className="landing-nav-login" href="/login">Masuk <ArrowRight size={15} /></Link>
				</nav>
			</header>

			<section className="landing-hero" aria-labelledby="landing-title">
				<div className="landing-hero-copy">
					<p className="landing-eyebrow"><span /> Panduan penggunaan MIRA</p>
					<h1 id="landing-title">Laporkan Masalah Mesin dengan Mudah</h1>
					<p className="landing-lead">Mesin bermasalah? Pilih mesinnya, ceritakan masalahnya, lalu tim perbaikan akan menindaklanjuti. Tidak perlu memahami istilah teknis.</p>
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
				<div className="landing-section-heading"><p className="landing-eyebrow">Tidak perlu keahlian teknis</p><h2>Cara Menggunakan Aplikasi</h2><p>Cukup ikuti langkah berikut. Gunakan kata-kata sehari-hari untuk menjelaskan masalah mesin.</p></div>
				<div className="landing-step-grid">
					{steps.map((step, index) => <article className="landing-step-card" key={step.title}>
						<div className="landing-step-top"><StepIcon icon={step.icon} /><span className="landing-step-number">0{index + 1}</span></div>
						<h3>{step.title}</h3><p>{step.description}</p>
					</article>)}
				</div>
			</section>

			<section className="landing-section landing-flow-section" id="alur-maintenance">
				<div className="landing-section-heading landing-section-heading-inline"><div><p className="landing-eyebrow">Apa yang terjadi setelah melapor?</p><h2>Proses Penanganan</h2></div><p>Anda mengirim laporan, tim perbaikan memeriksa mesin, lalu mencatat hasilnya sampai pekerjaan selesai.</p></div>
				<ol className="landing-flow">
					{flow.map((item, index) => <li key={item}><span className="landing-flow-dot">{index === flow.length - 1 ? <Check size={19} /> : index === 2 ? <TicketCheck size={19} /> : index === 3 ? <Wrench size={19} /> : index === 0 ? <Smartphone size={19} /> : <ClipboardList size={19} />}</span><span className="landing-flow-label">{item}</span>{index < flow.length - 1 && <ArrowRight className="landing-flow-arrow" size={18} />}</li>)}
				</ol>
			</section>

			<section className="landing-section landing-roles-section">
				<div className="landing-section-heading"><p className="landing-eyebrow">Semua punya tugas yang jelas</p><h2>Siapa yang Menggunakan Aplikasi?</h2><p>Anda cukup melaporkan masalah. Tim perbaikan menangani mesin, sementara Admin membantu mengatur data aplikasi.</p></div>
				<div className="landing-role-grid">
					<article className="landing-role-card"><span className="landing-role-icon"><Smartphone size={23} /></span><h3>Pelapor (Operator)</h3><p>Orang yang menemukan masalah mesin. Buat laporan dan lihat perkembangannya.</p><span className="landing-role-flow">Pilih Mesin <ArrowRight size={14} /> Laporkan</span></article>
					<article className="landing-role-card"><span className="landing-role-icon"><Wrench size={23} /></span><h3>Tim Perbaikan</h3><p>Memeriksa mesin, melakukan perbaikan, dan mencatat hasil pekerjaan.</p><span className="landing-role-flow">Periksa <ArrowRight size={14} /> Perbaiki <ArrowRight size={14} /> Catat</span></article>
					<article className="landing-role-card"><span className="landing-role-icon"><Settings2 size={23} /></span><h3>Admin Aplikasi</h3><p>Orang yang membantu mengatur akun pengguna dan daftar lokasi serta mesin.</p><span className="landing-role-flow">Atur Data <ArrowRight size={14} /></span></article>
				</div>
			</section>

			<section className="landing-section landing-guide-section">
				<div className="landing-guide-copy"><p className="landing-eyebrow">Untuk pelapor</p><h2>Cara Melaporkan Mesin</h2><p>Ikuti langkah ini dari HP. Kode QR adalah gambar kotak pada mesin. Jika tidak bisa dipindai, pilih lokasi dan mesin dari daftar.</p>
					<ol className="landing-guide-list"><li><span>1</span>Buka MIRA dan tekan tombol “+”</li><li><span>2</span>Pindai gambar kotak pada mesin, atau pilih lokasi dan mesin</li><li><span>3</span>Pilih masalah yang paling mirip. Jika ragu, pilih “Lainnya”</li><li><span>4</span>Ceritakan apa yang terjadi dengan kata-kata biasa</li><li><span>5</span>Tambahkan foto jika membantu</li><li><span>6</span>Tekan “Simpan Laporan”</li></ol>
				</div>
				<div className="landing-guide-visual landing-operator-visual" aria-label="Ilustrasi langkah operator membuat laporan">
					<div className="landing-guide-phone"><span className="landing-phone-camera" /><div className="landing-phone-appbar"><Image src={`${basePath}/qa-logo.png`} alt="" width={23} height={23} unoptimized /><span>MIRA</span><Bell size={15} /></div><div className="landing-phone-welcome">Halo!</div><div className="landing-phone-scan"><QrCode size={44} /><span>Pilih atau pindai mesin</span></div><div className="landing-phone-report"><span>Laporan terbaru</span><strong>Mesin berhenti</strong><small>Menunggu tim perbaikan</small></div><span className="landing-phone-add">+</span></div>
				<div className="landing-guide-caption"><QrCode size={18} /><span><strong>Pilih cara yang mudah</strong><small>Pindai QR atau pilih mesin dari daftar</small></span></div>
				</div>
			</section>

			<section className="landing-section landing-guide-section landing-maintenance-section">
				<div className="landing-guide-visual landing-maintenance-visual" aria-label="Contoh laporan mesin dan catatan perbaikan">
					<div className="landing-ticket-illustration"><div className="landing-ticket-head"><span><TicketCheck size={18} /> Laporan Perbaikan</span><b>BARU</b></div><small>Contoh laporan</small><h3>Mesin berhenti</h3><div className="landing-ticket-machine"><Factory size={16} /> Lokasi: Plant 3 · Breyer 1</div><div className="landing-ticket-divider" /><div className="landing-ticket-progress"><span><i /> Diperiksa</span><span><i /> Diperbaiki</span><span><i /> Selesai</span></div><div className="landing-ticket-work"><Wrench size={17} /><span><b>Tulis hasil perbaikan</b><small>Apa penyebab dan yang sudah dilakukan?</small></span></div></div>
					<div className="landing-guide-caption"><Wrench size={18} /><span><strong>Kerjakan dan catat</strong><small>Catatan dapat dibaca kembali</small></span></div>
				</div>
				<div className="landing-guide-copy"><p className="landing-eyebrow">Untuk tim perbaikan</p><h2>Cara Menangani Laporan</h2><p>Setiap laporan berisi lokasi, mesin, dan cerita dari pelapor. Periksa mesin, lalu catat pekerjaan dengan bahasa yang mudah dipahami.</p>
					<ol className="landing-maintenance-flow"><li><span>01</span><strong>Buka laporan baru</strong></li><li><span>02</span><strong>Periksa mesin</strong></li><li><span>03</span><strong>Catat penyebabnya</strong></li><li><span>04</span><strong>Catat perbaikan dan hasil</strong></li><li><span>05</span><strong>Tandai pekerjaan selesai</strong></li></ol>
				</div>
			</section>

			<section className="landing-notification-section">
				<div className="landing-notification-icon"><Bell size={25} /></div>
				<div className="landing-notification-copy"><p className="landing-eyebrow">Proses selalu terpantau</p><h2>Anda Tahu Perkembangannya</h2><p>Pemberitahuan memberi tahu pelapor dan tim perbaikan saat ada kabar baru.</p></div>
				<div className="landing-notification-flow"><div><span className="landing-notification-symbol"><TicketCheck size={18} /></span><span><small>Saat laporan masuk</small><strong>Tim perbaikan mendapat kabar</strong></span></div><ArrowDown size={17} /><div><span className="landing-notification-symbol"><Check size={18} /></span><span><small>Saat pekerjaan selesai</small><strong>Pelapor mendapat kabar</strong></span></div></div>
			</section>

			<footer className="landing-footer"><Brand /><span>Pelaporan masalah dan riwayat perbaikan mesin dalam satu aplikasi.</span><Link href="/login" className="landing-footer-link">Masuk ke MIRA <ArrowRight size={15} /></Link></footer>
		</main>
	);
}
