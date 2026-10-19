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

if (empty($_SESSION['csrf_absensi'])) {
    $_SESSION['csrf_absensi'] = bin2hex(random_bytes(32));
}

$pesan = '';
$jenisPesan = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_absensi'], $_POST['csrf_token'])
        ) {
            throw new RuntimeException('Permintaan tidak valid. Muat ulang halaman.');
        }

        $karyawanId = filter_input(
            INPUT_POST,
            'karyawan_id',
            FILTER_VALIDATE_INT
        );

        $aksi = $_POST['aksi'] ?? '';

        if (!$karyawanId || !in_array($aksi, ['masuk', 'pulang'], true)) {
            throw new RuntimeException('Pilih karyawan dan tindakan yang benar.');
        }

        // Pastikan karyawan masih aktif.
        $stmt = $pdo->prepare(
            "SELECT id FROM karyawan WHERE id = ? AND status = 'Aktif'"
        );
        $stmt->execute([$karyawanId]);

        if (!$stmt->fetch()) {
            throw new RuntimeException('Karyawan tidak ditemukan atau tidak aktif.');
        }

        $tanggal = date('Y-m-d');
        $jamSekarang = date('H:i:s');

        if ($aksi === 'masuk') {
            // Status terlambat jika masuk setelah pukul 08.00.
            $status = ($jamSekarang > '08:00:00')
                ? 'Terlambat'
                : 'Hadir';

            $stmt = $pdo->prepare(
                "INSERT INTO absensi
                    (karyawan_id, tanggal, jam_masuk, status)
                 VALUES (?, ?, ?, ?)"
            );

            try {
                $stmt->execute([
                    $karyawanId,
                    $tanggal,
                    $jamSekarang,
                    $status
                ]);
            } catch (PDOException $e) {
                // Kode MySQL 1062 berarti data tanggal/karyawan sudah ada.
                if (
                    isset($e->errorInfo[1]) &&
                    (int)$e->errorInfo[1] === 1062
                ) {
                    throw new RuntimeException(
                        'Karyawan ini sudah memiliki catatan absensi hari ini.'
                    );
                }
                throw $e;
            }

            $pesan = 'Jam masuk berhasil dicatat pada ' . $jamSekarang .
                '. Status: ' . $status . '.';
        }

        if ($aksi === 'pulang') {
            $stmt = $pdo->prepare(
                "UPDATE absensi
                 SET jam_pulang = ?
                 WHERE karyawan_id = ?
                   AND tanggal = ?
                   AND jam_masuk IS NOT NULL
                   AND jam_pulang IS NULL"
            );

            $stmt->execute([
                $jamSekarang,
                $karyawanId,
                $tanggal
            ]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException(
                    'Catatan masuk belum ada atau jam pulang sudah dicatat.'
                );
            }

            $pesan = 'Jam pulang berhasil dicatat pada ' . $jamSekarang . '.';
        }
    } catch (RuntimeException $e) {
        $pesan = $e->getMessage();
        $jenisPesan = 'danger';
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $pesan = 'Terjadi kesalahan database. Periksa struktur tabel absensi.';
        $jenisPesan = 'danger';
    }
}

// Daftar karyawan aktif untuk formulir.
$stmt = $pdo->query(
    "SELECT id, nip, nama, departemen
     FROM karyawan
     WHERE status = 'Aktif'
     ORDER BY nama ASC"
);
$karyawan = $stmt->fetchAll();

// Daftar absensi hari ini.
$stmt = $pdo->prepare(
    "SELECT a.*, k.nip, k.nama, k.departemen
     FROM absensi a
     JOIN karyawan k ON k.id = a.karyawan_id
     WHERE a.tanggal = ?
     ORDER BY a.id DESC"
);
$stmt->execute([date('Y-m-d')]);
$daftarAbsensi = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Absensi Harian</title>
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
        <a class="navbar-brand fw-bold" href="dashboard.php">
            AbsensiKita
        </a>
        <a href="logout.php" class="btn btn-light btn-sm">Logout</a>
    </div>
</nav>

<div class="container py-4">
    <div class="mb-4">
        <h3 class="fw-bold">Absensi Harian</h3>
        <p class="text-secondary mb-0">
            Tanggal <?= h(date('d-m-Y')) ?> · Waktu server
            <?= h(date('H:i:s')) ?> WIB
        </p>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-<?= h($jenisPesan) ?> alert-dismissible fade show">
            <?= h($pesan) ?>
            <button type="button" class="btn-close"
                    data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card main-card shadow-sm mb-4">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-3">Catat Kehadiran Karyawan</h5>

            <?php if (!$karyawan): ?>
                <div class="alert alert-warning">
                    Belum ada karyawan aktif.
                    <a href="karyawan.php">Tambahkan data karyawan terlebih dahulu.</a>
                </div>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token"
                           value="<?= h($_SESSION['csrf_absensi']) ?>">

                    <div class="mb-3">
                        <label for="karyawan_id" class="form-label">
                            Pilih Karyawan
                        </label>
                        <select class="form-select" name="karyawan_id"
                                id="karyawan_id" required>
                            <option value="">-- Pilih karyawan --</option>
                            <?php foreach ($karyawan as $k): ?>
                                <option value="<?= (int)$k['id'] ?>">
                                    <?= h($k['nip']) ?> -
                                    <?= h($k['nama']) ?> -
                                    <?= h($k['departemen']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" name="aksi" value="masuk"
                                class="btn btn-success">
                            Catat Jam Masuk
                        </button>
                        <button type="submit" name="aksi" value="pulang"
                                class="btn btn-warning">
                            Catat Jam Pulang
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card main-card shadow-sm">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-3">Daftar Kehadiran Hari Ini</h5>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>No.</th>
                        <th>NIP</th>
                        <th>Nama</th>
                        <th>Departemen</th>
                        <th>Jam Masuk</th>
                        <th>Jam Pulang</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$daftarAbsensi): ?>
                        <tr>
                            <td colspan="7" class="text-center text-secondary">
                                Belum ada absensi hari ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($daftarAbsensi as $i => $a): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= h($a['nip']) ?></td>
                                <td><?= h($a['nama']) ?></td>
                                <td><?= h($a['departemen']) ?></td>
                                <td><?= h($a['jam_masuk'] ?? '-') ?></td>
                                <td><?= h($a['jam_pulang'] ?? '-') ?></td>
                                <td>
                                    <?php if ($a['status'] === 'Terlambat'): ?>
                                        <span class="badge text-bg-warning">
                                            Terlambat
                                        </span>
                                    <?php else: ?>
                                        <span class="badge text-bg-success">
                                            <?= h($a['status']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <a href="dashboard.php" class="btn btn-outline-primary mt-3">
        Kembali ke Dashboard
    </a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>