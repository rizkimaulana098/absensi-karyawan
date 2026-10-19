
<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/config/koneksi.php';

/* Validasi login Admin */
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

/* Statistik dashboard */
$totalKaryawan = (int)$pdo->query(
    "SELECT COUNT(*) FROM karyawan WHERE status = 'Aktif'"
)->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM absensi WHERE tanggal = ?"
);
$stmt->execute([date('Y-m-d')]);
$totalAbsen = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM absensi
     WHERE tanggal = ? AND status = 'Terlambat'"
);
$stmt->execute([date('Y-m-d')]);
$totalTerlambat = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM absensi
     WHERE tanggal = ? AND jam_pulang IS NOT NULL"
);
$stmt->execute([date('Y-m-d')]);
$totalPulang = (int)$stmt->fetchColumn();

/* Data absensi terbaru */
$stmt = $pdo->query(
    "SELECT
        a.id,
        a.tanggal,
        a.jam_masuk,
        a.jam_pulang,
        a.status,
        a.foto_masuk,
        a.latitude_masuk,
        a.longitude_masuk,
        k.nip,
        k.nama,
        k.departemen
     FROM absensi a
     JOIN karyawan k ON k.id = a.karyawan_id
     ORDER BY a.tanggal DESC, a.id DESC
     LIMIT 10"
);
$absensiTerbaru = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Dashboard Admin - AbsensiKita</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        body {
            background: #f3f6fb;
            font-family: Arial, sans-serif;
        }

        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, #172554, #1e3a8a);
        }

        .brand {
            color: white;
            font-size: 22px;
            font-weight: bold;
            padding: 18px 12px;
        }

        .sidebar a {
            color: #e5e7eb;
            text-decoration: none;
            display: block;
            padding: 12px 14px;
            margin-bottom: 5px;
            border-radius: 9px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            color: white;
            background: #2563eb;
        }

        .stat-card {
            border: 0;
            border-radius: 16px;
            transition: transform .2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
        }

        .main-card {
            border: 0;
            border-radius: 16px;
        }

        .topbar {
            background: white;
            border-radius: 14px;
            padding: 18px 22px;
        }

        .table th,
        .table td {
            vertical-align: middle;
        }

        .foto-mini {
            width: 48px;
            height: 48px;
            object-fit: cover;
            border-radius: 8px;
        }

        @media (max-width: 767px) {
            .sidebar {
                min-height: auto;
            }
        }
    </style>
</head>

<body>
<div class="container-fluid">
    <div class="row">

        <!-- SIDEBAR -->
        <aside class="col-md-3 col-lg-2 sidebar p-3">
            <div class="brand">AbsensiKita</div>

            <div class="text-uppercase text-white-50 small mb-2 mt-2">
                Menu Utama
            </div>

            <a href="dashboard_admin.php" class="active">
                🏠 Dashboard
            </a>

            <a href="karyawan.php">
                👥 Data Karyawan
            </a>

            <a href="akun_karyawan.php">
                🔑 Akun Karyawan
            </a>

            <a href="absensi.php">
                📅 Absensi Harian
            </a>

            <a href="admin_absensi_bukti.php">
                📸 Bukti Selfie & GPS
            </a>
            <a href="laporan_absensi.php">
    📊 Laporan Bulanan
</a>

<a href="admin_pengajuan.php">
    📝 Pengajuan Izin dan Cuti
</a>

            <a href="admin_absensi_bukti.php">
                📍 Lokasi Absensi
            </a>

            <hr class="border-light">

            <a href="logout.php">
                🚪 Logout
            </a>
        </aside>

        <!-- KONTEN UTAMA -->
        <main class="col-md-9 col-lg-10 p-3 p-lg-4">

            <div class="topbar shadow-sm mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h3 class="fw-bold mb-1">Dashboard Admin</h3>
                        <div class="text-secondary">
                            Selamat datang,
                            <?= h($_SESSION['username'] ?? 'Admin') ?>!
                        </div>
                    </div>

                    <div class="text-end">
                        <div class="fw-semibold">
                            <?= h(date('d-m-Y')) ?>
                        </div>
                        <small class="text-secondary">
                            Sistem Absensi Karyawan
                        </small>
                    </div>
                </div>
            </div>

            <!-- KARTU STATISTIK -->
            <div class="row g-3 mb-4">

                <div class="col-sm-6 col-xl-3">
                    <div class="card stat-card shadow-sm">
                        <div class="card-body p-4">
                            <div class="text-secondary">Karyawan Aktif</div>
                            <div class="stat-number text-primary">
                                <?= $totalKaryawan ?>
                            </div>
                            <a href="karyawan.php"
                               class="small text-decoration-none">
                                Kelola karyawan →
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <div class="card stat-card shadow-sm">
                        <div class="card-body p-4">
                            <div class="text-secondary">Sudah Absen Hari Ini</div>
                            <div class="stat-number text-success">
                                <?= $totalAbsen ?>
                            </div>
                            <small class="text-secondary">
                                Data tanggal hari ini
                            </small>
                        </div>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <div class="card stat-card shadow-sm">
                        <div class="card-body p-4">
                            <div class="text-secondary">Terlambat</div>
                            <div class="stat-number text-danger">
                                <?= $totalTerlambat ?>
                            </div>
                            <small class="text-secondary">
                                Masuk setelah pukul 08.00
                            </small>
                        </div>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <div class="card stat-card shadow-sm">
                        <div class="card-body p-4">
                            <div class="text-secondary">Sudah Absen Pulang</div>
                            <div class="stat-number text-warning">
                                <?= $totalPulang ?>
                            </div>
                            <small class="text-secondary">
                                Data hari ini
                            </small>
                        </div>
                    </div>
                </div>

            </div>

            <!-- AKSES CEPAT -->
            <div class="card main-card shadow-sm mb-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Akses Cepat</h5>

                    <div class="d-flex flex-wrap gap-2">
                        <a href="karyawan.php" class="btn btn-primary">
                            + Kelola Karyawan
                        </a>

                        <a href="akun_karyawan.php" class="btn btn-outline-primary">
                            Kelola Akun
                        </a>

                        <a href="absensi.php" class="btn btn-success">
                            Absensi Harian
                        </a>

                        <a href="admin_absensi_bukti.php" class="btn btn-dark">
                            📸 Lihat Bukti Selfie & GPS
                        </a>
                    </div>
                </div>
            </div>

            <!-- TABEL ABSENSI TERBARU -->
            <div class="card main-card shadow-sm">
                <div class="card-body p-3 p-lg-4">

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <h5 class="fw-bold mb-1">Absensi Terbaru</h5>
                            <p class="text-secondary small mb-0">
                                Sepuluh catatan absensi terakhir.
                            </p>
                        </div>

                        <a href="admin_absensi_bukti.php"
                           class="btn btn-outline-primary btn-sm">
                            Lihat Semua Bukti →
                        </a>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>Tanggal</th>
                                <th>NIP</th>
                                <th>Nama</th>
                                <th>Departemen</th>
                                <th>Masuk</th>
                                <th>Pulang</th>
                                <th>Foto</th>
                                <th>Status</th>
                                <th>Detail</th>
                            </tr>
                            </thead>

                            <tbody>
                            <?php if (!$absensiTerbaru): ?>
                                <tr>
                                    <td colspan="9"
                                        class="text-center text-secondary py-4">
                                        Belum ada data absensi.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($absensiTerbaru as $a): ?>
                                    <tr>
                                        <td><?= h($a['tanggal']) ?></td>
                                        <td><?= h($a['nip']) ?></td>
                                        <td><?= h($a['nama']) ?></td>
                                        <td><?= h($a['departemen'] ?? '-') ?></td>
                                        <td><?= h($a['jam_masuk'] ?? '-') ?></td>
                                        <td><?= h($a['jam_pulang'] ?? '-') ?></td>

                                        <td>
                                            <?php
                                            $foto = $a['foto_masuk'] ?? '';
                                            $fotoValid = is_string($foto)
                                                && preg_match(
                                                    '#^uploads/absensi/[A-Za-z0-9_-]+\.jpg$#',
                                                    $foto
                                                )
                                                && is_file(__DIR__ . '/' . $foto);
                                            ?>

                                            <?php if ($fotoValid): ?>
                                                <a href="<?= h($foto) ?>"
                                                   target="_blank" rel="noopener">
                                                    <img src="<?= h($foto) ?>"
                                                         class="foto-mini"
                                                         alt="Selfie masuk">
                                                </a>
                                            <?php else: ?>
                                                <span class="text-secondary small">
                                                    Belum ada
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if ($a['status'] === 'Hadir'): ?>
                                                <span class="badge text-bg-success">
                                                    Hadir
                                                </span>
                                            <?php elseif ($a['status'] === 'Terlambat'): ?>
                                                <span class="badge text-bg-danger">
                                                    Terlambat
                                                </span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">
                                                    <?= h($a['status'] ?? '-') ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <a href="admin_absensi_bukti.php?tanggal=<?= h($a['tanggal']) ?>"
                                               class="btn btn-sm btn-outline-primary">
                                                Detail
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>

            <p class="text-center text-secondary small mt-4">
                AbsensiKita &copy; <?= date('Y') ?> — Sistem Absensi Karyawan
            </p>

        </main>
    </div>
</div>
</body>
</html>
