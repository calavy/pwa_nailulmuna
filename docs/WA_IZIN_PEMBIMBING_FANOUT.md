# WA izin disetujui → pembimbing: fan-out vs duplikat

## Gejala

- **Fan-out lebar:** satu izin → **banyak pembimbing berbeda** masing-masing dapat satu WA (bukan bug dedup).
- **Duplikat:** **nomor yang sama** dapat pesan **identik** lebih dari sekali untuk izin yang sama.

## Konfigurasi paling aman (disarankan produksi)

| Setting | Nilai aman |
|---------|------------|
| `wa_izin_pembimbing_sumber` | **`pkpps_only`** — hanya pembimbing PKPPS yang terhubung ke santri |
| `wa_izin_pembimbing_max_recipients` | **`3`** |
| `wa_izin_pembimbing_fallback_scope` | **`0`** — tidak otomatis blast ke lingkup dashboard jika PKPPS kosong |

Setelah deploy, jika DB sudah punya baris lama di `app_settings`, buka **Pengaturan → WA Otomatis → Izin** dan **Simpan** sekali agar nilai aman tersimpan.

Mode lain (lebih longgar):

| Mode | Perilaku |
|------|----------|
| `pkpps_setoran` | PKPPS + setoran tingkatan, setoran difilter `pembimbing_dashboard_santri_dalam_scope` |
| `scope` | PKPPS + setoran scoped + semua pembimbing dalam lingkup dashboard |
| `legacy_jadwal_tingkatan` | Union jadwal global per tingkatan (fan-out lebar) |

Fallback scope (checkbox UI) hanya jalan jika daftar dari mode aktif **kosong**.

## Resolver penerima

Logika di `helpers/perizinan_approval.php`. Prioritas gabungan: **PKPPS** → **setoran (scoped)** → **scope/jadwal** (tergantung mode).

UI: **Pengaturan → WA Otomatis → tab Izin → Izin disetujui → pembimbing**.

## Dedup kirim

Per izin + saluran pembimbing + nomor: `izin:{id}:pembimbing:approved:t:{62…}`  
Tabel: `wa_dispatch_log` (`http_ok = 1` = sudah sukses).

Antrean `perizinan_notif_queue` memakai **`claimed_at`** (klaim atomik) sebelum kirim agar cron paralel tidak memproses baris yang sama.

## Diagnostik

```bash
php scripts/_diag_wa_izin_pembimbing_fanout.php
php scripts/_diag_wa_izin_pembimbing_fanout.php --izin=123
php scripts/_diag_wa_izin_pembimbing_fanout.php --santri=456
```

Interpretasi:

- Banyak baris `wa_dispatch_log` dengan **target_phone berbeda** → fan-out; gunakan `pkpps_only` atau turunkan max recipients.
- **target_phone sama** beberapa kali dengan `http_ok=1` → investigasi retry gateway (`wa_dispatch_release`).

## Checklist prod (read-only)

1. Jalankan diag untuk satu `izin_id` yang dulu spam → `Resolver final` ≤ 3 dan mostly PKPPS.
2. Cek `wa_dispatch_log` prefix `izin:{id}:pembimbing:approved:` — satu sukses per nomor.
3. Konfirmasi setting di UI = paling aman (atau sesuai kebijakan pondok).

## Alur defer

Setujui izin → `perizinan_notif_queue` → cron `cron/wa_auto.php` → `perizinan_kirim_wa_pembimbing_disetujui()`.
