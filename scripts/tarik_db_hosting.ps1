# Salin database hosting ke XAMPP lokal (pwa_nailulmuna).
# Hosting tidak diubah. Database lokal ditimpa hanya setelah cadangan berhasil
# dan file SQL hosting sudah ada.
# Jangan jalankan cron/wa_auto.php setelah impor: itu bisa mengirim WhatsApp sungguhan.
param(
    [string]$SqlFile = ''
)

$ErrorActionPreference = 'Stop'

$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Mysql = 'C:\xampp\mysql\bin\mysql.exe'
$Dump = 'C:\xampp\mysql\bin\mysqldump.exe'
$Php = 'C:\xampp\php\php.exe'
$LocalDb = 'pwa_nailulmuna'
$BackupDir = Join-Path $Root 'backups'

function Fail([string]$Message) {
    throw $Message
}

$cnf = $null
$remoteDb = $null

function Remove-Cnf {
    if ($cnf -and (Test-Path -LiteralPath $cnf)) {
        Remove-Item -LiteralPath $cnf -Force -ErrorAction SilentlyContinue
    }
}

function Read-HostingProfile {
    $profile = Join-Path $Root 'config\database.local.hosting.php'
    if (-not (Test-Path -LiteralPath $profile)) {
        Fail @"
File config\database.local.hosting.php belum ada.
Salin config\database.local.hosting.example.php menjadi file itu, isi host dan sandi dari panel hosting, lalu jalankan skrip ini lagi.
Sandi tidak ditulis ke Git.
"@
    }

    $script:cnf = Join-Path $env:TEMP ("pondok-db-hosting-" + [guid]::NewGuid().ToString('n') + '.cnf')
    $parser = Join-Path $env:TEMP ("pondok-db-hosting-" + [guid]::NewGuid().ToString('n') + '.php')
    @'
<?php
$file = $argv[1] ?? '';
$cnf = $argv[2] ?? '';
if (!is_file($file)) {
    fwrite(STDERR, "missing\n");
    exit(2);
}
$c = require $file;
if (!is_array($c)) {
    fwrite(STDERR, "invalid\n");
    exit(3);
}
foreach (['host', 'port', 'dbname', 'user', 'pass'] as $key) {
    $val = trim((string) ($c[$key] ?? ''));
    if ($val === '' || $val === 'GANTI_PASSWORD_DARI_PANEL') {
        fwrite(STDERR, "field:$key\n");
        exit(4);
    }
}
$pass = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $c['pass']);
$ini = "[client]\nhost=\"" . $c['host'] . "\"\nport=" . $c['port'] . "\nuser=\"" . $c['user'] . "\"\npassword=\"" . $pass . "\"\n";
if (file_put_contents($cnf, $ini) === false) {
    fwrite(STDERR, "cnf\n");
    exit(5);
}
echo json_encode([
    'host' => (string) $c['host'],
    'port' => (string) $c['port'],
    'dbname' => (string) $c['dbname'],
    'user' => (string) $c['user'],
], JSON_UNESCAPED_UNICODE);
'@ | Set-Content -LiteralPath $parser -Encoding ascii
    try {
        $json = & $Php $parser $profile $cnf 2>&1
        $code = $LASTEXITCODE
    } finally {
        Remove-Item -LiteralPath $parser -Force -ErrorAction SilentlyContinue
    }
    if ($code -ne 0) {
        Remove-Cnf
        $text = ($json | Out-String).Trim()
        if ($text -match 'field:') {
            Fail "config\database.local.hosting.php belum lengkap. Isi host, port, dbname, user, dan sandi dari panel hosting."
        }
        Fail "config\database.local.hosting.php tidak bisa dibaca."
    }
    $meta = $json | Out-String | ConvertFrom-Json
    $script:remoteDb = [string] $meta.dbname
    Write-Host ("Profil hosting: " + $meta.user + "@" + $meta.host + ":" + $meta.port + " / " + $meta.dbname)
}

function Backup-Local {
    New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
    $stamp = Get-Date -Format 'yyyyMMdd-HHmm'
    $path = Join-Path $BackupDir ("lokal-sebelum-tarik-" + $stamp + ".sql")
    & $Dump --user=root --default-character-set=utf8mb4 --single-transaction --routines --triggers --result-file=$path $LocalDb
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $path)) {
        Fail "Cadangan database lokal gagal. Database lokal tidak diubah."
    }
    Write-Host "Cadangan lokal: $path"
    return $path
}

function Import-Local([string]$Path) {
    Write-Host "Mengganti isi database lokal $LocalDb ..."
    & $Mysql --user=root --default-character-set=utf8mb4 -e "DROP DATABASE IF EXISTS $LocalDb; CREATE DATABASE $LocalDb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    if ($LASTEXITCODE -ne 0) {
        Fail "Gagal menyiapkan database lokal. Pulihkan dari file cadangan di folder backups bila perlu."
    }

    $proc = New-Object System.Diagnostics.Process
    $proc.StartInfo.FileName = $Mysql
    $proc.StartInfo.Arguments = "--user=root --default-character-set=utf8mb4 $LocalDb"
    $proc.StartInfo.UseShellExecute = $false
    $proc.StartInfo.RedirectStandardInput = $true
    $proc.StartInfo.RedirectStandardError = $true
    $proc.StartInfo.CreateNoWindow = $true
    [void]$proc.Start()

    $reader = [System.IO.StreamReader]::new($Path)
    $skipUntilSemi = $false
    try {
        while ($null -ne ($line = $reader.ReadLine())) {
            $trim = $line.Trim()
            if ($skipUntilSemi) {
                if ($trim.EndsWith(';')) { $skipUntilSemi = $false }
                continue
            }
            if ($trim -match '^(CREATE\s+(DATABASE|SCHEMA)|USE)\b') {
                if (-not $trim.EndsWith(';')) { $skipUntilSemi = $true }
                continue
            }
            $proc.StandardInput.WriteLine($line)
        }
    } finally {
        $reader.Close()
        $proc.StandardInput.Close()
    }
    $err = $proc.StandardError.ReadToEnd()
    $proc.WaitForExit()
    if ($proc.ExitCode -ne 0) {
        if ($err) { Write-Host $err }
        Fail "Impor gagal. Database lokal mungkin belum utuh. Pulihkan file cadangan di folder backups."
    }
    Write-Host "Impor selesai ke $LocalDb. Jangan jalankan cron WA di komputer ini."
}

try {
    foreach ($bin in @($Mysql, $Dump, $Php)) {
        if (-not (Test-Path -LiteralPath $bin)) {
            Fail "Tidak menemukan $bin"
        }
    }
    if ($SqlFile -ne '') {
        if (-not [System.IO.Path]::IsPathRooted($SqlFile)) {
            $SqlFile = Join-Path $Root $SqlFile
        }
        if (-not (Test-Path -LiteralPath $SqlFile)) {
            Fail "File SQL tidak ada: $SqlFile"
        }
    }
    if ($SqlFile -eq '') {
        Read-HostingProfile
        [void](Backup-Local)
        $stamp = Get-Date -Format 'yyyyMMdd-HHmm'
        $hostingDump = Join-Path $BackupDir ("hosting-" + $stamp + ".sql")
        Write-Host "Menyalin database hosting (hanya baca) ..."
        & $Dump --defaults-extra-file=$cnf --connect-timeout=20 --protocol=TCP --single-transaction --routines --triggers --default-character-set=utf8mb4 --result-file=$hostingDump $remoteDb
        if ($LASTEXITCODE -ne 0) {
            if (Test-Path -LiteralPath $hostingDump) {
                Remove-Item -LiteralPath $hostingDump -Force -ErrorAction SilentlyContinue
            }
            Fail @"
Koneksi ke hosting gagal atau ditolak. Database lokal tidak diubah (cadangan sudah disimpan).
Ekspor SQL dari phpMyAdmin hosting, simpan misalnya sebagai backups\hosting.sql, lalu jalankan:
  powershell -File scripts\tarik_db_hosting.ps1 -SqlFile backups\hosting.sql
"@
        }
        Import-Local $hostingDump
    } else {
        [void](Backup-Local)
        Import-Local $SqlFile
    }
} catch {
    Remove-Cnf
    Write-Host $_.Exception.Message
    exit 1
} finally {
    Remove-Cnf
}
