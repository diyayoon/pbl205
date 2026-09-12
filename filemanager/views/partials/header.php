<?php
/* HTML Head — Partial */

$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="id" class="h-full dark">
<head>



<!-- ── DARK MODE: cek localStorage sebelum render agar tidak flicker ── -->
<script>
  (function() {
    var saved = localStorage.getItem('darkMode');
    if (saved === 'light') {
      document.documentElement.classList.remove('dark');
    } else {
      document.documentElement.classList.add('dark');
    }
    document.documentElement.classList.add('initialized');
  })();
</script>
<style> html:not(.initialized) { visibility: hidden; } </style>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
<meta name="robots" content="noindex, nofollow">



<!-- ── DEPENDENCIES: Tailwind, Google Fonts, Lucide Icons, custom CSS ── -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: '#8b5cf6',
          hover:   '#7c3aed',
          active:  '#4f46e5',
          light:   '#ede9fe',
        }
      },
      fontFamily: { sans: ['Inter', 'sans-serif'] }
    }
  }
}
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..800&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">

<style>
* { font-family: 'Inter', sans-serif; }
html, body { min-height: 100%; }



/* ── BACKGROUND: dark mode default (gradient + grid overlay) ── */
body {
  background:
    radial-gradient(circle at 12% 12%, rgba(139, 92, 246, .22), transparent 25%),
    radial-gradient(circle at 88% 10%, rgba(14, 165, 233, .19), transparent 29%),
    radial-gradient(circle at 70% 78%, rgba(217, 70, 239, .13), transparent 28%),
    linear-gradient(135deg, #020617 0%, #070b22 38%, #111747 67%, #07111f 100%);
  color: #e5edff;
}



/* grid dot overlay */
body::before {
  content: "";
  position: fixed;
  inset: 0;
  pointer-events: none;
  z-index: 0;
  background-image:
    linear-gradient(rgba(147, 197, 253, 0.035) 1px, transparent 1px),
    linear-gradient(90deg, rgba(167, 139, 250, 0.032) 1px, transparent 1px),
    radial-gradient(circle at 20% 20%, rgba(255,255,255,.08) 0 1px, transparent 2px),
    radial-gradient(circle at 80% 35%, rgba(125,211,252,.12) 0 1px, transparent 2px);
  background-size: 46px 46px, 46px 46px, 150px 150px, 210px 210px;
}



/* diagonal gradient overlay */
body::after {
  content: "";
  position: fixed;
  inset: 0;
  z-index: 0;
  pointer-events: none;
  background:
    linear-gradient(115deg, transparent 0%, rgba(96, 165, 250, .04) 32%, transparent 45%),
    linear-gradient(245deg, transparent 0%, rgba(168, 85, 247, .05) 46%, transparent 58%);
}

#appLayout { position: relative; z-index: 1; }



/* ── PANEL & CARD: komponen container utama di semua halaman ── */
.cyber-panel {
  border: 1px solid rgba(167, 139, 250, .16);
  background: linear-gradient(135deg, rgba(15, 23, 42, .62), rgba(30, 27, 75, .34)), rgba(255, 255, 255, .035);
  backdrop-filter: blur(18px);
  box-shadow: 0 24px 70px rgba(2, 6, 23, .34), inset 0 0 0 1px rgba(255,255,255,.035);
}

.cyber-panel-soft {
  border: 1px solid rgba(147, 197, 253, .12);
  background: rgba(255,255,255,.045);
  backdrop-filter: blur(16px);
  box-shadow: 0 18px 50px rgba(2, 6, 23, .25);
}



/* dipakai di dashboard.php (stat card) */
.cyber-card {
  position: relative;
  overflow: hidden;
  border: 1px solid rgba(167, 139, 250, .17);
  background:
    radial-gradient(circle at 15% 0%, rgba(96, 165, 250, .18), transparent 34%),
    radial-gradient(circle at 90% 5%, rgba(217, 70, 239, .16), transparent 31%),
    rgba(255, 255, 255, .045);
  backdrop-filter: blur(18px);
  box-shadow: 0 20px 55px rgba(2, 6, 23, .26), inset 0 0 0 1px rgba(255,255,255,.03);
}

.cyber-card::before {
  content: "";
  position: absolute;
  inset: 0;
  pointer-events: none;
  background: linear-gradient(120deg, rgba(255,255,255,.12), transparent 28%, transparent 72%, rgba(125,211,252,.08));
  opacity: .35;
}

.cyber-card > * { position: relative; z-index: 1; }

.cyber-card:hover {
  border-color: rgba(196, 181, 253, .32);
  background:
    radial-gradient(circle at 15% 0%, rgba(96, 165, 250, .22), transparent 34%),
    radial-gradient(circle at 90% 5%, rgba(217, 70, 239, .2), transparent 31%),
    rgba(255, 255, 255, .065);
  transform: translateY(-1px);
}



/* ── ICON ORB: ikon bulat di dashboard stat card & empty state ── */
.icon-orb {
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 1rem;
  background: linear-gradient(135deg, rgba(59, 130, 246, .25), rgba(168, 85, 247, .25));
  border: 1px solid rgba(191, 219, 254, .14);
  box-shadow: 0 12px 35px rgba(124, 58, 237, .23);
}



/* ── LOGO & TEKS GRADIENT: dipakai di sidebar & header ── */
.crystal-logo {
  background: linear-gradient(135deg, #2563eb, #8b5cf6 52%, #d946ef);
  box-shadow: 0 18px 45px rgba(124, 58, 237, .42), inset 0 0 0 1px rgba(255,255,255,.24);
}

.gradient-text {
  background: linear-gradient(90deg, #93c5fd, #c4b5fd, #f0abfc);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}



/* ── BUTTON: neon-button dipakai di semua modal & form ── */
.neon-button {
  background: linear-gradient(90deg, #2563eb, #7c3aed, #d946ef);
  color: white;
  box-shadow: 0 16px 38px rgba(124, 58, 237, .28);
}
.neon-button:hover { filter: brightness(1.08); transform: translateY(-1px); }



/* ── FILE PICKER: tombol "Pilih File" di modal upload (files.php) ── */
input[type="file"].file-picker-input::file-selector-button {
  background: linear-gradient(90deg, #2563eb, #7c3aed, #d946ef);
  color: #ffffff;
  font-weight: 700;
  border: 0;
  border-radius: 0.75rem;
  padding: 0.5rem 0.85rem;
  margin-right: 1rem;
  box-shadow: 0 10px 25px rgba(124, 58, 237, .25);
  cursor: pointer;
  transition: filter .15s ease, transform .15s ease;
}
input[type="file"].file-picker-input::file-selector-button:hover {
  filter: brightness(1.08);
  transform: translateY(-1px);
}



/* ── BADGE: badge-soft dipakai di berbagai label kecil ── */
.badge-soft {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: .25rem .65rem;
  font-size: .72rem;
  font-weight: 700;
  border: 1px solid rgba(196, 181, 253, .18);
  background: rgba(139, 92, 246, .12);
  color: #ddd6fe;
}



/* ── SIDEBAR: link navigasi di sidebar.php ── */
.sidebar-link {
  display: flex;
  align-items: center;
  gap: .75rem;
  padding: .78rem .9rem;
  border-radius: 1rem;
  font-size: .875rem;
  font-weight: 700;
  transition: all .18s ease;
  position: relative;
}
.sidebar-link:hover { background: rgba(99, 102, 241, .16); color: white; transform: translateX(2px); }
.sidebar-link.active {
  background: linear-gradient(90deg, rgba(37, 99, 235, .33), rgba(124, 58, 237, .42));
  color: white;
  box-shadow: 0 16px 42px rgba(79, 70, 229, .26), inset 0 0 0 1px rgba(255, 255, 255, .10);
}
.sidebar-link.active::before {
  content: "";
  position: absolute;
  left: -0.28rem;
  top: 50%;
  transform: translateY(-50%);
  width: 4px;
  height: 44%;
  border-radius: 999px;
  background: linear-gradient(180deg, #60a5fa, #f0abfc);
  box-shadow: 0 0 20px rgba(96, 165, 250, .85);
}
.sidebar-link:not(.active) { color: rgba(219, 234, 254, .74); }



/* ── TABLE: cyber-table dipakai di admin-users.php ── */
.cyber-table thead tr { background: rgba(255,255,255,.045); border-bottom: 1px solid rgba(255,255,255,.10); color: rgba(219, 234, 254, .78); }
.cyber-table tbody tr { border-bottom: 1px solid rgba(255,255,255,.09); color: rgba(239,246,255,.92); transition: background .16s ease; }
.cyber-table tbody tr:hover { background: rgba(255,255,255,.055); }



/* ── SCROLLBAR: global custom scrollbar ── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(129, 140, 248, .45); border-radius: 99px; }
::-webkit-scrollbar-thumb:hover { background: rgba(167, 139, 250, .75); }



/* ── TOAST: notifikasi pop-up (footer.php > showToast) ── */
#toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; }
.toast { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 14px; box-shadow: 0 18px 45px rgba(2, 6, 23, .35); min-width: 280px; max-width: 380px; animation: slideIn .3s ease; font-size: 14px; font-weight: 600; backdrop-filter: blur(16px); }
@keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
@keyframes slideOut { to { transform: translateX(110%); opacity: 0; } }
.toast-success { background: rgba(5, 46, 22, .92); border: 1px solid rgba(34, 197, 94, .35); color: #bbf7d0; }
.toast-error { background: rgba(69, 10, 10, .92); border: 1px solid rgba(248, 113, 113, .35); color: #fecaca; }
.toast-info { background: rgba(30, 41, 59, .94); border: 1px solid rgba(129, 140, 248, .35); color: #bfdbfe; }
.drop-zone.drag-over { border-color: #8b5cf6 !important; background: rgba(124, 58, 237, 0.08) !important; }




/* ── FILE ACTION BUTTONS: tombol aksi per baris di tabel files.php ── */
.file-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  padding: .38rem .72rem;
  font-size: .78rem;
  font-weight: 800;
  line-height: 1;
  border: 1px solid transparent;
  transition: all .16s ease;
  white-space: nowrap;
}



.file-action:hover { transform: translateY(-1px); }
.file-action-access  { color: #2dd4bf; background: rgba(20,184,166,.18); border-color: rgba(45,212,191,.55); }
.file-action-rename  { color: #fde68a; background: rgba(245,158,11,.12); border-color: rgba(251,191,36,.18); }
.file-action-move    { color: #c4b5fd; background: rgba(124,58,237,.12); border-color: rgba(167,139,250,.18); }
.file-action-delete  { color: #fecaca; background: rgba(239,68,68,.12); border-color: rgba(248,113,113,.18); }



/* ── SCROLL AREAS: area scroll di files.php (folder list & file table) ── */
.folder-scroll-area { max-height: min(44vh, 430px); overflow-y: auto; padding-right: .25rem; }
.file-table-scroll { max-height: min(56vh, 560px); overflow: auto; }
.file-table-scroll thead tr { position: sticky; top: 0; z-index: 12; }



/* ── ROLE & DEPT BADGES: label role/dept di admin-users.php ── */
.superadmin-shield { color: #facc15; }
.superadmin-badge { border: 1px solid rgba(253,224,71,.35); background: rgba(234,179,8,.12); color: #fde68a; }
.role-badge-admin { background: rgba(139, 92, 246, .18) !important; border: 1px solid rgba(167, 139, 250, .35) !important; color: #c4b5fd !important; }
.role-badge-member { background: rgba(59, 130, 246, .15) !important; border: 1px solid rgba(96, 165, 250, .35) !important; color: #93c5fd !important; }

.dept-badge-superadmin { background: rgba(234,179,8,.12); border: 1px solid rgba(253,224,71,.35); color: #fde68a; }
.dept-badge-member-only { background: rgba(100,116,139,.20); border: 1px solid rgba(148,163,184,.35); color: #cbd5e1; }
.dept-badge-admin { background: rgba(139, 92, 246, .18); border: 1px solid rgba(167, 139, 250, .35); color: #c4b5fd; }
.dept-badge-member { background: rgba(59, 130, 246, .15); border: 1px solid rgba(96, 165, 250, .35); color: #93c5fd; }



/* ── DARK SCROLLBAR OVERRIDE ── */
.dark { scrollbar-color: rgba(139, 92, 246, .56) transparent; }
.dark ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, rgba(96, 165, 250, .44), rgba(139, 92, 246, .62)) !important; border-radius: 999px; }
.dark ::-webkit-scrollbar-thumb:hover { background: linear-gradient(180deg, rgba(96, 165, 250, .68), rgba(167, 139, 250, .82)) !important; }



/* ═══════════════════════════════════════════════════════════
   LIGHT MODE OVERRIDES
   Semua di bawah ini adalah override warna untuk light mode.
   Dikelompokkan per komponen supaya mudah dicari.
   ═══════════════════════════════════════════════════════════ */



/* ── Background & body ── */
html:not(.dark) body,
html:not(.dark) #appBody {
  background:
    radial-gradient(circle at 10% 10%, rgba(186, 230, 253, .72), transparent 30%),
    radial-gradient(circle at 82% 12%, rgba(147, 197, 253, .62), transparent 32%),
    radial-gradient(circle at 65% 75%, rgba(224, 242, 254, .82), transparent 34%),
    linear-gradient(135deg, #f8fdff 0%, #e7f7ff 34%, #d9efff 68%, #f5fbff 100%) !important;
  color: #0f172a !important;
}

html:not(.dark) body::before {
  background-image:
    linear-gradient(rgba(14, 165, 233, 0.10) 1px, transparent 1px),
    linear-gradient(90deg, rgba(59, 130, 246, 0.08) 1px, transparent 1px),
    radial-gradient(circle at 20% 20%, rgba(14,165,233,.22) 0 1px, transparent 2px),
    radial-gradient(circle at 80% 35%, rgba(96,165,250,.24) 0 1px, transparent 2px);
}

html:not(.dark) body::after {
  background:
    linear-gradient(115deg, transparent 0%, rgba(14, 165, 233, .075) 30%, transparent 48%),
    linear-gradient(245deg, transparent 0%, rgba(96, 165, 250, .08) 42%, transparent 62%) !important;
}



/* ── Navbar & Sidebar ── */
html:not(.dark) header,
html:not(.dark) #sidebar {
  background: rgba(235, 249, 255, .86) !important;
  color: #0f172a !important;
  border-color: rgba(14, 165, 233, .28) !important;
  box-shadow: 0 18px 50px rgba(14, 165, 233, .16) !important;
}

html:not(.dark) .sidebar-link:not(.active) { color: #475569 !important; }
html:not(.dark) .sidebar-link:hover { background: rgba(14, 165, 233, .11) !important; color: #0f172a !important; }
html:not(.dark) .sidebar-link.active {
  background: linear-gradient(90deg, rgba(56, 189, 248, .30), rgba(96, 165, 250, .26)) !important;
  color: #0f172a !important;
  box-shadow: 0 16px 42px rgba(14,165,233,.18), inset 0 0 0 1px rgba(14,165,233,.18) !important;
}
html:not(.dark) .sidebar-link.active::before {
  background: linear-gradient(180deg, #38bdf8, #0284c7) !important;
  box-shadow: 0 0 18px rgba(56, 189, 248, .72) !important;
}



/* ── Panel & Card ── */
html:not(.dark) .cyber-panel,
html:not(.dark) .cyber-card,
html:not(.dark) .cyber-panel-soft,
html:not(.dark) #userMenu {
  border-color: rgba(14, 165, 233, .30) !important;
  background: linear-gradient(135deg, rgba(255,255,255,.86), rgba(219, 242, 255,.64)), rgba(255,255,255,.72) !important;
  color: #0f172a !important;
  box-shadow: 0 22px 55px rgba(14, 165, 233, .16), inset 0 0 0 1px rgba(255,255,255,.72) !important;
}

html:not(.dark) .cyber-card:hover {
  border-color: rgba(2, 132, 199, .42) !important;
  background: linear-gradient(135deg, rgba(255,255,255,.92), rgba(186, 230, 253,.70)), rgba(255,255,255,.78) !important;
}



/* ── Icon Orb & Logo ── */
html:not(.dark) .crystal-logo,
html:not(.dark) .icon-orb {
  background: linear-gradient(135deg, #38bdf8 0%, #60a5fa 55%, #bae6fd 100%) !important;
  border-color: rgba(14, 165, 233, .25) !important;
  box-shadow: 0 14px 35px rgba(14, 165, 233, .25), inset 0 0 0 1px rgba(255,255,255,.62) !important;
}



/* ── Buttons ── */
html:not(.dark) .neon-button {
  background: linear-gradient(90deg, #0ea5e9 0%, #2563eb 55%, #22d3ee 100%) !important;
  color: #ffffff !important;
  box-shadow: 0 16px 36px rgba(14, 165, 233, .24) !important;
}

html:not(.dark) input[type="file"].file-picker-input::file-selector-button {
  background: linear-gradient(90deg, #0ea5e9 0%, #2563eb 55%, #22d3ee 100%) !important;
  color: #0b0b14 !important;
  box-shadow: 0 10px 25px rgba(14, 165, 233, .22) !important;
}

html:not(.dark) .search-btn { border-color: rgba(14, 165, 233, .34) !important; background: rgba(255, 255, 255, 0.9) !important; }

/* ── Teks & gradient text ── */
html:not(.dark) .gradient-text {
  background: linear-gradient(90deg, #0369a1, #2563eb, #0891b2) !important;
  -webkit-background-clip: text !important;
  background-clip: text !important;
  color: transparent !important;
}

html:not(.dark) .text-white,
html:not(.dark) .text-slate-100,
html:not(.dark) .text-slate-200,
html:not(.dark) [class*="text-blue-50"],
html:not(.dark) [class*="text-blue-100"],
html:not(.dark) [class*="text-blue-200"],
html:not(.dark) [class*="text-indigo-100"],
html:not(.dark) [class*="text-indigo-200"],
html:not(.dark) [class*="text-purple-100"],
html:not(.dark) [class*="text-purple-200"],
html:not(.dark) [class*="text-fuchsia-100"],
html:not(.dark) [class*="text-fuchsia-200"] { color: #0f172a !important; }

html:not(.dark) .text-slate-300,
html:not(.dark) .text-slate-400,
html:not(.dark) .text-gray-300,
html:not(.dark) .text-gray-400,
html:not(.dark) .text-gray-500,
html:not(.dark) [class*="text-white/"],
html:not(.dark) [class*="text-blue-100/"] { color: #475569 !important; }

html:not(.dark) .text-red-300, html:not(.dark) .text-red-400 { color: #dc2626 !important; }
html:not(.dark) .text-emerald-200, html:not(.dark) .text-emerald-300 { color: #047857 !important; }
html:not(.dark) .text-yellow-300, html:not(.dark) .text-amber-300 { color: #b45309 !important; }
html:not(.dark) .text-purple-300, html:not(.dark) .text-fuchsia-300 { color: #0284c7 !important; }
html:not(.dark) [class*="text-purple"], html:not(.dark) [class*="text-fuchsia"], html:not(.dark) [class*="text-indigo"] { color: #0369a1 !important; }
html:not(.dark) [class*="text-cyan-200"], html:not(.dark) [class*="text-cyan-300"] { color: #075985 !important; }



/* ── Badge ── */
html:not(.dark) .badge-soft {
  background: rgba(14, 165, 233, .12) !important;
  border-color: rgba(14, 165, 233, .24) !important;
  color: #075985 !important;
}



/* ── Role & Dept badges ── */
html:not(.dark) .superadmin-shield { color: #d97706 !important; }
html:not(.dark) .superadmin-badge { background: rgba(245,158,11,.18) !important; border: 1px solid rgba(217,119,6,.35) !important; color: #92400e !important; font-weight: 700; }
html:not(.dark) .role-badge-admin { background: rgba(139, 92, 246, .14) !important; border: 1px solid rgba(139, 92, 246, .38) !important; color: #6d28d9 !important; }
html:not(.dark) .role-badge-member { background: rgba(37, 99, 235, .14) !important; border: 1px solid rgba(37, 99, 235, .38) !important; color: #1d4ed8 !important; font-weight: 700; }

html:not(.dark) .dept-badge-superadmin { background: rgba(245,158,11,.18) !important; border: 1px solid rgba(217,119,6,.35) !important; color: #92400e !important; font-weight: 700; }
html:not(.dark) .dept-badge-member-only { background: rgba(100,116,139,.16) !important; border: 1px solid rgba(100,116,139,.38) !important; color: #334155 !important; font-weight: 700; }
html:not(.dark) .dept-badge-admin { background: rgba(139, 92, 246, .14) !important; border: 1px solid rgba(139, 92, 246, .38) !important; color: #6d28d9 !important; font-weight: 700; }
html:not(.dark) .dept-badge-member { background: rgba(37, 99, 235, .14) !important; border: 1px solid rgba(37, 99, 235, .38) !important; color: #1d4ed8 !important; font-weight: 700; }



/* ── Form inputs ── */
html:not(.dark) input,
html:not(.dark) select,
html:not(.dark) textarea {
  background: rgba(255,255,255,.82) !important;
  color: #0f172a !important;
  border-color: rgba(14, 165, 233, .34) !important;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,.38) !important;
}
html:not(.dark) input::placeholder,
html:not(.dark) textarea::placeholder { color: #64748b !important; }
html:not(.dark) option { background: #f0f9ff !important; color: #0f172a !important; }



/* ── Table (cyber-table di admin-users.php) ── */
html:not(.dark) .cyber-table thead tr {
  background: rgba(255,255,255,.62) !important;
  border-bottom: 1px solid rgba(14,165,233,.24) !important;
  color: #334155 !important;
}
html:not(.dark) .cyber-table tbody tr {
  border-bottom: 1px solid rgba(14,165,233,.16) !important;
  color: #1e293b !important;
}
html:not(.dark) .cyber-table tbody tr:hover { background: rgba(14, 165, 233, .08) !important; }



/* ── File table (files.php) ── */
html:not(.dark) .file-table-scroll thead tr { background: rgba(240, 249, 255, .92) !important; color: #334155 !important; }
html:not(.dark) .file-table-scroll tbody tr { color: #0f172a !important; }
html:not(.dark) .file-table-scroll tbody tr:hover { background: rgba(224, 242, 254, .55) !important; }



/* ── Folder list (files.php sidebar kiri) ── */
html:not(.dark) .folder-scroll-area .group, html:not(.dark) .folder-scroll-area a { color: #334155; }
html:not(.dark) .folder-scroll-area .group[class*="from-blue"],
html:not(.dark) .folder-scroll-area a[class*="from-blue"] {
  color: #075985 !important;
  background: linear-gradient(90deg, rgba(125,211,252,.55), rgba(34,211,238,.60)) !important;
  box-shadow: inset 0 0 0 1px rgba(14,165,233,.18);
}



/* ── File action buttons (files.php) ── */
html:not(.dark) .file-action-access  { color: #0f766e !important; background: rgba(45,212,191,.22) !important; border-color: rgba(20,184,166,.70) !important; }
html:not(.dark) .file-action-rename  { color: #92400e !important; background: rgba(251,191,36,.18) !important; border-color: rgba(251,191,36,.45) !important; }
html:not(.dark) .file-action-move    { color: #1d4ed8 !important; background: rgba(37,99,235,.14) !important; border-color: rgba(37,99,235,.32) !important; }
html:not(.dark) .file-action-delete  { color: #dc2626 !important; background: rgba(239,68,68,.12) !important; border-color: rgba(239,68,68,.34) !important; }



/* ── Toast notifications ── */
html:not(.dark) .toast-success { background: rgba(236, 253, 245, .98); border-color: rgba(16,185,129,.30); color: #065f46; }
html:not(.dark) .toast-error   { background: rgba(254, 242, 242, .98); border-color: rgba(239,68,68,.32); color: #991b1b; }
html:not(.dark) .toast-info    { background: rgba(240, 249, 255, .98); border-color: rgba(14,165,233,.32); color: #075985; }



/* ── Member access panel (dashboard.php) ── */
html:not(.dark) .member-access-panel a,
html:not(.dark) .member-access-panel tr,
html:not(.dark) .member-access-panel td { color: #0f172a !important; }
html:not(.dark) .member-access-panel .muted-text { color: #475569 !important; }
html:not(.dark) .member-access-panel .access-link { color: #0369a1 !important; }



/* ── Profile page (profile.php) ── */
html:not(.dark) .profile-card {
  border: 1px solid rgba(14, 165, 233, .42) !important;
  background: linear-gradient(135deg, rgba(255,255,255,.78), rgba(186,230,253,.58)) !important;
  box-shadow: 0 26px 60px rgba(15, 23, 42, .16), inset 0 0 0 1px rgba(255,255,255,.72) !important;
}
html:not(.dark) .profile-field {
  border: 1px solid rgba(14, 165, 233, .34) !important;
  background: rgba(255,255,255,.54) !important;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,.56) !important;
}

/* ── Upload progress bar (files.php modal upload) ── */
html:not(.dark) #progressBar { background-image: linear-gradient(90deg, #38bdf8, #0ea5e9, #22d3ee) !important; }



/* ── Warna generik utility override (purple/fuchsia/indigo → biru di light mode) ── */
html:not(.dark) [class*="bg-purple"],
html:not(.dark) [class*="bg-fuchsia"],
html:not(.dark) [class*="bg-indigo"] { background-color: rgba(14, 165, 233, .13) !important; }
html:not(.dark) [class*="border-purple"],
html:not(.dark) [class*="border-fuchsia"],
html:not(.dark) [class*="border-indigo"] { border-color: rgba(14, 165, 233, .28) !important; }
html:not(.dark) [class*="ring-purple"],
html:not(.dark) [class*="ring-indigo"] { --tw-ring-color: rgba(14, 165, 233, .35) !important; }
html:not(.dark) [class*="shadow-purple"],
html:not(.dark) [class*="shadow-fuchsia"],
html:not(.dark) [class*="shadow-indigo"] { --tw-shadow-color: rgba(14, 165, 233, .28) !important; --tw-shadow: var(--tw-shadow-colored) !important; }

html:not(.dark) .bg-gradient-to-br,
html:not(.dark) .bg-gradient-to-r {
  --tw-gradient-from: #38bdf8 var(--tw-gradient-from-position) !important;
  --tw-gradient-to: #22d3ee var(--tw-gradient-to-position) !important;
  --tw-gradient-stops: var(--tw-gradient-from), #60a5fa var(--tw-gradient-via-position), var(--tw-gradient-to) !important;
}

html:not(.dark) .sidebar-link.active,
html:not(.dark) .cyber-table .bg-gradient-to-r,
html:not(.dark) a.bg-gradient-to-r,
html:not(.dark) .group.bg-gradient-to-r {
  background-image: linear-gradient(90deg, rgba(186,230,253,.78), rgba(125,211,252,.52)) !important;
  color: #0f172a !important;
}

html:not(.dark) .accent-purple-500 { accent-color: #0ea5e9 !important; }
html:not(.dark) .focus\:ring-purple-500\/40:focus,
html:not(.dark) .focus\:ring-purple-500:focus { --tw-ring-color: rgba(14, 165, 233, .36) !important; }
html:not(.dark) .focus\:border-purple-400\/40:focus { border-color: rgba(14,165,233,.42) !important; }

html:not(.dark) a:not(.sidebar-link) { color: #0369a1; }
html:not(.dark) button:not(.neon-button) { color: inherit; }



/* ── Scrollbar light mode ── */
html:not(.dark) { scrollbar-color: rgba(14, 165, 233, .62) transparent; }
html:not(.dark) ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, rgba(56, 189, 248, .72), rgba(14, 165, 233, .78)) !important; border-radius: 999px; }
html:not(.dark) ::-webkit-scrollbar-thumb:hover { background: linear-gradient(180deg, rgba(14, 165, 233, .90), rgba(2, 132, 199, .92)) !important; }
html:not(.dark) ::-webkit-scrollbar-track { background: rgba(224, 242, 254, .32) !important; }
html:not(.dark) .folder-scroll-area::-webkit-scrollbar-thumb,
html:not(.dark) .file-table-scroll::-webkit-scrollbar-thumb { background: rgba(14, 165, 233, .42); }
</style>
</head>

<body class="h-full bg-slate-950 text-slate-100 transition-colors duration-200" id="appBody">
<div class="flex h-full" id="appLayout">