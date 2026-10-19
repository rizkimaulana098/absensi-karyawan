
<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'karyawan'
) {
    header('Location: login.php');
    exit;
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Ambil data karyawan berdasarkan akun yang sedang login.
$stmt = $pdo->prepare(
    "SELECT k.id, k.nip, k.nama, k.departemen, k.jabatan
     FROM users u
     INNER JOIN karyawan k ON k.id = u.karyawan_id
     WHERE u.id = ? AND u.role = 'karyawan'
     LIMIT 1"
);
$stmt->execute([$_SESSION['user_id']]);
$karyawan = $stmt->fetch();

if (!$karyawan) {
    exit('Data karyawan tidak ditemukan. Hubungi Admin.');
}

$karyawanId = (int)$karyawan['id'];

// Ambil absensi hari ini.
$stmt = $pdo->prepare(
    "SELECT jam_masuk, jam_pulang, status
     FROM absensi
     WHERE karyawan_id = ? AND tanggal = CURDATE()
     LIMIT 1"
);
$stmt->execute([$karyawanId]);
$absensiHariIni = $stmt->fetch();

// Ambil 10 riwayat absensi terakhir.
$stmt = $pdo->prepare(
    "SELECT tanggal, jam_masuk, jam_pulang, status
     FROM absensi
     WHERE karyawan_id = ?
     ORDER BY tanggal DESC
     LIMIT 10"
);
$stmt->execute([$karyawanId]);
$riwayat = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Karyawan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
    <style>
        body { background: #f4f7fb; }
        .main-card { border: 0; border-radius: 16px; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand fw-bold" href="dashboard_karyawan.php">
            AbsensiKita
        </a>
        <a href="logout.php" class="btn btn-light btn-sm">Logout</a>
    </div>
</nav>

<div class="container py-4">
    <div class="card main-card shadow-sm mb-4">
        <div class="card-body p-4">
            <p class="text-secondary mb-1">Dashboard Karyawan</p>
            <h3 class="fw-bold">
                Halo, <?= h($karyawan['nama']) ?>!
            </h3>
            <p class="mb-1">NIP: <?= h($karyawan['nip']) ?></p>
            <p class="mb-1">
                Departemen: <?= h($karyawan['departemen']) ?>
            </p>
            <p class="mb-0">
                Jabatan: <?= h($karyawan['jabatan']) ?>
            </p>
        </div>
    </div>

    <div class="card main-card shadow-sm mb-4">
        <div class="card-body p-4">
            <h5 class="fw-bold">Absensi Hari Ini</h5>
            <p class="text-secondary">
                <?= h(date('d-m-Y')) ?>
            </p>

            <?php if (!$absensiHariIni): ?>
                <p>Jam masuk belum tercatat.</p>
                <p>Jam pulang belum tercatat.</p>
            <?php else: ?>
                <p>
                    Jam masuk:
                    <strong>
                        <?= h($absensiHariIni['jam_masuk'] ?? '-') ?>
                    </strong>
                </p>
                <p>
                    Jam pulang:
                    <strong>
                        <?= h($absensiHariIni['jam_pulang'] ?? '-') ?>
                    </strong>
                </p>
                <p>
                    Status:
                    <span class="badge text-bg-primary">
                        <?= h($absensiHariIni['status']) ?>
                    </span>
                </p>
            <?php endif; ?>

            <a href="absensi_karyawan.php" class="btn btn-success">
                Buka Absensi Saya
            </a>
            <a href="pengajuan_izin.php" class="btn btn-outline-primary">
    📝 Pengajuan Izin dan Cuti
</a>
        </div>
    </div>

    <div class="card main-card shadow-sm">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-3">Riwayat Absensi Saya</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Tanggal</th>
                        <th>Jam Masuk</th>
                        <th>Jam Pulang</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$riwayat): ?>
                        <tr>
                            <td colspan="4" class="text-center text-secondary">
                                Belum ada riwayat absensi.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($riwayat as $r): ?>
                            <tr>
                                <td><?= h($r['tanggal']) ?></td>
                                <td><?= h($r['jam_masuk'] ?? '-') ?></td>
                                <td><?= h($r['jam_pulang'] ?? '-') ?></td>
                                <td><?= h($r['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
