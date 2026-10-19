
<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/config/koneksi.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    exit('Halaman ini khusus Admin.');
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* Filter bulan dan tahun */
$bulan = filter_input(INPUT_GET, 'bulan', FILTER_VALIDATE_INT);
$tahun = filter_input(INPUT_GET, 'tahun', FILTER_VALIDATE_INT);

if (!$bulan || $bulan < 1 || $bulan > 12) {
    $bulan = (int)date('m');
}

if (!$tahun || $tahun < 2000 || $tahun > 2100) {
    $tahun = (int)date('Y');
}

$tanggalMulai = sprintf('%04d-%02d-01', $tahun, $bulan);
$tanggalSelesai = date(
    'Y-m-d',
    strtotime($tanggalMulai . ' +1 month')
);

$namaBulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret',
    4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September',
    10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$periode = $namaBulan[$bulan] . ' ' . $tahun;

/* Rekap per karyawan */
$stmt = $pdo->prepare(
    "SELECT
        k.id,
        k.nip,
        k.nama,
        k.departemen,
        COUNT(a.id) AS total_absen,
        COALESCE(SUM(CASE WHEN a.status = 'Hadir'
            THEN 1 ELSE 0 END), 0) AS total_hadir,
        COALESCE(SUM(CASE WHEN a.status = 'Terlambat'
            THEN 1 ELSE 0 END), 0) AS total_terlambat,
        COALESCE(SUM(CASE WHEN a.jam_pulang IS NOT NULL
            THEN 1 ELSE 0 END), 0) AS total_pulang
     FROM karyawan k
     LEFT JOIN absensi a
        ON a.karyawan_id = k.id
        AND a.tanggal >= ?
        AND a.tanggal < ?
     WHERE k.status = 'Aktif'
     GROUP BY k.id, k.nip, k.nama, k.departemen
     ORDER BY k.nama ASC"
);
$stmt->execute([$tanggalMulai, $tanggalSelesai]);
$rekap = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Detail seluruh absensi pada periode terpilih */
$stmt = $pdo->prepare(
    "SELECT
        a.tanggal,
        k.nip,
        k.nama,
        k.departemen,
        a.jam_masuk,
        a.jam_pulang,
        a.status
     FROM absensi a
     JOIN karyawan k ON k.id = a.karyawan_id
     WHERE a.tanggal >= ? AND a.tanggal < ?
     ORDER BY a.tanggal ASC, k.nama ASC"
);
$stmt->execute([$tanggalMulai, $tanggalSelesai]);
$detail = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Total laporan */
$totalHadir = 0;
$totalTerlambat = 0;
$totalPulang = 0;

foreach ($rekap as $r) {
    $totalHadir += (int)$r['total_hadir'];
    $totalTerlambat += (int)$r['total_terlambat'];
    $totalPulang += (int)$r['total_pulang'];
}

/* Export CSV yang dapat dibuka menggunakan Excel */
if (($_GET['export'] ?? '') === 'excel') {
    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="laporan_absensi_' .
        $tahun . '_' . sprintf('%02d', $bulan) . '.csv"'
    );

    $output = fopen('php://output', 'w');

    /* BOM agar karakter Indonesia terbaca baik di Excel */
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, ['LAPORAN ABSENSI KARYAWAN']);
    fputcsv($output, ['Periode', $periode]);
    fputcsv($output, []);
    fputcsv($output, [
        'Tanggal', 'NIP', 'Nama Karyawan', 'Departemen',
        'Jam Masuk', 'Jam Pulang', 'Status'
    ]);

    foreach ($detail as $d) {
        fputcsv($output, [
            $d['tanggal'],
            $d['nip'],
            $d['nama'],
            $d['departemen'],
            $d['jam_masuk'],
            $d['jam_pulang'],
            $d['status']
        ]);
    }

    fputcsv($output, []);
    fputcsv($output, ['REKAP PER KARYAWAN']);
    fputcsv($output, [
        'NIP', 'Nama Karyawan', 'Departemen',
        'Total Absen', 'Hadir', 'Terlambat', 'Sudah Pulang'
    ]);

    foreach ($rekap as $r) {
        fputcsv($output, [
            $r['nip'],
            $r['nama'],
            $r['departemen'],
            $r['total_absen'],
            $r['total_hadir'],
            $r['total_terlambat'],
            $r['total_pulang']
        ]);
    }

    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan Absensi Bulanan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        body {
            background: #f3f6fb;
        }

        .main-card {
            border: 0;
            border-radius: 16px;
        }

        .stat-card {
            border: 0;
            border-radius: 14px;
        }

        .table th,
        .table td {
            vertical-align: middle;
            white-space: nowrap;
        }

        @media print {
            .no-print,
            nav,
            .filter-card,
            .btn {
                display: none !important;
            }

            body {
                background: white;
                font-size: 11px;
            }

            .container-fluid {
                padding: 0 !important;
            }

            .main-card,
            .stat-card {
                border: 1px solid #ccc !important;
                box-shadow: none !important;
            }

            h3, h5 {
                color: black !important;
            }

            table {
                width: 100% !important;
            }

            .table-responsive {
                overflow: visible !important;
            }

            .page-break {
                break-before: page;
            }
        }
    </style>
</head>

<body>
<nav class="navbar navbar-dark bg-primary no-print">
    <div class="container-fluid px-4">
        <a href="dashboard_admin.php" class="navbar-brand fw-bold">
            AbsensiKita · Admin
        </a>
        <a href="logout.php" class="btn btn-light btn-sm">Logout</a>
    </div>
</nav>

<div class="container-fluid px-3 px-lg-4 py-4">

    <div class="d-flex justify-content-between align-items-center
                flex-wrap gap-2 mb-4">
        <div>
            <h3 class="fw-bold mb-1">Laporan Absensi Bulanan</h3>
            <p class="text-secondary mb-0">
                Periode laporan: <?= h($periode) ?>
            </p>
        </div>

        <a href="dashboard_admin.php"
           class="btn btn-outline-primary no-print">
            Kembali ke Dashboard
        </a>
    </div>

    <!-- Filter dan tombol export -->
    <div class="card main-card shadow-sm mb-4 filter-card">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label fw-semibold">Bulan</label>
                    <select name="bulan" class="form-select">
                        <?php foreach ($namaBulan as $nomor => $nama): ?>
                            <option value="<?= $nomor ?>"
                                <?= $bulan === $nomor ? 'selected' : '' ?>>
                                <?= h($nama) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-sm-3 col-lg-2">
                    <label class="form-label fw-semibold">Tahun</label>
                    <select name="tahun" class="form-select">
                        <?php for ($y = (int)date('Y'); $y >= 2020; $y--): ?>
                            <option value="<?= $y ?>"
                                <?= $tahun === $y ? 'selected' : '' ?>>
                                <?= $y ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-sm-5 col-lg-3">
                    <button type="submit" class="btn btn-primary w-100">
                        Tampilkan Laporan
                    </button>
                </div>

                <div class="col-sm-6 col-lg-2">
                    <button type="button"
                            onclick="window.print()"
                            class="btn btn-danger w-100">
                        Cetak / PDF
                    </button>
                </div>

                <div class="col-sm-6 col-lg-2">
                    <button type="submit"
                            name="export"
                            value="excel"
                            class="btn btn-success w-100">
                        Export Excel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Ringkasan -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card stat-card shadow-sm">
                <div class="card-body p-3">
                    <div class="text-secondary">Karyawan Aktif</div>
                    <div class="fs-2 fw-bold text-primary">
                        <?= count($rekap) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="card stat-card shadow-sm">
                <div class="card-body p-3">
                    <div class="text-secondary">Total Catatan Hadir</div>
                    <div class="fs-2 fw-bold text-success">
                        <?= $totalHadir ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="card stat-card shadow-sm">
                <div class="card-body p-3">
                    <div class="text-secondary">Total Terlambat</div>
                    <div class="fs-2 fw-bold text-danger">
                        <?= $totalTerlambat ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="card stat-card shadow-sm">
                <div class="card-body p-3">
                    <div class="text-secondary">Total Absen Pulang</div>
                    <div class="fs-2 fw-bold text-warning">
                        <?= $totalPulang ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Rekap per karyawan -->
    <div class="card main-card shadow-sm mb-4">
        <div class="card-body p-3 p-lg-4">
            <h5 class="fw-bold mb-3">Rekap per Karyawan</h5>

            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="table-primary">
                        <tr>
                            <th>No.</th>
                            <th>NIP</th>
                            <th>Nama Karyawan</th>
                            <th>Departemen</th>
                            <th>Total Absen</th>
                            <th>Hadir</th>
                            <th>Terlambat</th>
                            <th>Sudah Pulang</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$rekap): ?>
                        <tr>
                            <td colspan="8" class="text-center">
                                Tidak ada karyawan aktif.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rekap as $i => $r): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= h($r['nip']) ?></td>
                                <td><?= h($r['nama']) ?></td>
                                <td><?= h($r['departemen'] ?? '-') ?></td>
                                <td><?= (int)$r['total_absen'] ?></td>
                                <td><?= (int)$r['total_hadir'] ?></td>
                                <td><?= (int)$r['total_terlambat'] ?></td>
                                <td><?= (int)$r['total_pulang'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Detail absensi -->
    <div class="card main-card shadow-sm">
        <div class="card-body p-3 p-lg-4">
            <h5 class="fw-bold mb-3">Detail Absensi Bulanan</h5>

            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="table-primary">
                        <tr>
                            <th>No.</th>
                            <th>Tanggal</th>
                            <th>NIP</th>
                            <th>Nama Karyawan</th>
                            <th>Departemen</th>
                            <th>Jam Masuk</th>
                            <th>Jam Pulang</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$detail): ?>
                        <tr>
                            <td colspan="8" class="text-center">
                                Belum ada data absensi pada periode ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($detail as $i => $d): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= h($d['tanggal']) ?></td>
                                <td><?= h($d['nip']) ?></td>
                                <td><?= h($d['nama']) ?></td>
                                <td><?= h($d['departemen'] ?? '-') ?></td>
                                <td><?= h($d['jam_masuk'] ?? '-') ?></td>
                                <td><?= h($d['jam_pulang'] ?? '-') ?></td>
                                <td><?= h($d['status'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <p class="text-center text-secondary small mt-4">
        AbsensiKita &copy; <?= date('Y') ?>
    </p>
</div>
</body>
</html>
