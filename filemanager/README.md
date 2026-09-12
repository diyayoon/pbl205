# FileManager Pro

Struktur folder project:

- config
- controllers
- models
- views
- middleware
- helpers
- uploads
- assets
- sql
- scripts/powershell

## Konsep integrasi AD/Samba

Versi ini disesuaikan dengan kondisi lab:

- Group departemen sudah dibuat manual di Domain Controller.
- Folder/share utama departemen sudah dibuat manual di Samba, misalnya `FINANCE`, `HR`, dan `IT`.
- Website tidak membuat group/folder utama departemen lagi.
- Website hanya membuat user AD, memasukkan user ke group existing, dan membuat folder personal anggota di dalam share departemen.

Contoh hasil untuk anggota `andi` di Finance:

```text
\\sambafs.pbl205.local\FINANCE\andi
```

## Migration database wajib

Import database utama seperti biasa, lalu jalankan migration tambahan:

```sql
source sql/2026_06_member_folders.sql;
```

Migration ini menambah mapping pada tabel `folders`:

- `department_id`
- `owner_user_id`
- `folder_type`
- `samba_share`
- `samba_path`

Tanpa migration ini, website masih punya fallback lama, tetapi mapping folder anggota ke Samba tidak akan serapi mode baru.

## Konfigurasi AD/Samba

Lihat `.env.example` dan sesuaikan:

```text
PBL_FINANCE_MEMBER_GROUP=GG_FINANCE_Member
PBL_FINANCE_ADMIN_GROUP=GG_FINANCE_Admin
PBL_FINANCE_SHARE_PATH=\\sambafs.pbl205.local\FINANCE
```

Kalau nama group di DC kamu bukan `GG_FINANCE_Member`, ubah env tersebut sesuai nama group yang sudah kamu buat.

## Install singkat

1. Extract folder `filemanager` ke web root atau jalankan via Docker/Portainer.
2. Import database utama ke MySQL.
3. Jalankan `sql/2026_06_member_folders.sql`.
4. Copy `scripts/powershell/Create-AdUserFromWeb.ps1` ke Domain Controller, sesuai path `AD_PS_SCRIPT_PATH`.
5. Sesuaikan `.env` atau environment Portainer/Docker.
6. Pastikan WinRM aktif dan service account punya izin:
   - membuat user AD,
   - menambahkan member ke group existing,
   - membuat folder di share Samba existing,
   - mengatur ACL folder anggota.

## Alur role

```text
SuperAdmin
├── Membuat Admin Divisi
└── Membuat Anggota lintas departemen

Admin Divisi
├── Membuat Anggota hanya di departemennya
├── Folder personal anggota dibuat otomatis
└── Mengelola file/folder anggota di departemennya

Anggota
└── Mengakses folder personal dan file yang diberikan akses
```
