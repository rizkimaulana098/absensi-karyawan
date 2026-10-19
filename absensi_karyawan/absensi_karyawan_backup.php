
<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

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

if (empty($_SESSION['csrf_absensi_karyawan'])) {
    $_SESSION['csrf_absensi_karyawan'] = bin2hex(random_bytes(32));
}

$stmt = $pdo->prepare(
    "SELECT k.id, k.nama, k.nip
     FROM users u
     JOIN karyawan k ON k.id = u.karyawan_id
     WHERE u.id = ? AND u.role = 'karyawan'
       AND k.status = 'Aktif'
     LIMIT 1"
);
$stmt->execute([$_SESSION['user_id']]);
$karyawan = $stmt->fetch();

if (!$karyawan) {
    exit('Data karyawan tidak ditemukan atau tidak aktif. Hubungi Admin.');
}

$pesan = '';
$jenisPesan = 'success';
$karyawanId = (int)$karyawan['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals(
                $_SESSION['csrf_absensi_karyawan'],
                $_POST['csrf_token']
            )
        ) {
            throw new RuntimeException(
                'Permintaan tidak valid. Muat ulang halaman.'
            );
        }

        $aksi = $_POST['aksi'] ?? '';
        $tanggal = date('Y-m-d');
        $jam = date('H:i:s');

        if ($aksi === 'masuk') {
            $status = ($jam > '08:00:00') ? 'Terlambat' : 'Hadir';

            $stmt = $pdo->prepare(
                "INSERT INTO absensi
                    (karyawan_id, tanggal, jam_masuk, status)
                 VALUES (?, ?, ?, ?)"
            );

            try {
                $stmt->execute([
                    $karyawanId,
                    $tanggal,
                    $jam,
                    $status
                ]);
            } catch (PDOException $e) {
                if (
                    isset($e->errorInfo[1]) &&
                    (int)$e->errorInfo[1] === 1062
                ) {
                    throw new RuntimeException(
                        'Absensi hari ini sudah tercatat. Kamu tidak bisa mencatat masuk dua kali.'
                    );
                }
                throw $e;
            }

            $pesan = 'Jam masuk berhasil dicatat. Status: ' . $status . '.';

        } elseif ($aksi === 'pulang') {
            $stmt = $pdo->prepare(
                "UPDATE absensi
                 SET jam_pulang = ?
                 WHERE karyawan_id = ?
                   AND tanggal = ?
                   AND jam_masuk IS NOT NULL
                   AND jam_pulang IS NULL"
            );

            $stmt->execute([$jam, $karyawanId, $tanggal]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException(
                    'Jam masuk belum dicatat atau jam pulang sudah tercatat.'
                );
            }

            $pesan = 'Jam pulang berhasil dicatat.';
        } else {
            throw new RuntimeException('Tindakan tidak valid.');
        }

    } catch (RuntimeException $e) {
        $pesan = $e->getMessage();
        $jenisPesan = 'danger';

    } catch (PDOException $e) {
        error_log($e->getMessage());
        $pesan = 'Terjadi kesalahan database saat menyimpan absensi.';
        $jenisPesan = 'danger';
    }
}

$stmt = $pdo->prepare(
    "SELECT jam_masuk, jam_pulang, status
     FROM absensi
     WHERE karyawan_id = ? AND tanggal = ?
     LIMIT 1"
);
$stmt->execute([$karyawanId, date('Y-m-d')]);
$dataHariIni = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Absensi Saya</title>
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
        <a href="dashboard_karyawan.php" class="navbar-brand fw-bold">
            AbsensiKita
        </a>
        <a href="logout.php" class="btn btn-light btn-sm">Logout</a>
    </div>
</nav>

<div class="container py-4">
    <h3 class="fw-bold">Absensi Saya</h3>
    <p class="text-secondary">
        <?= h($karyawan['nama']) ?> · NIP <?= h($karyawan['nip']) ?>
    </p>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-<?= h($jenisPesan) ?>">
            <?= h($pesan) ?>
        </div>
    <?php endif; ?>

    <div class="card main-card shadow-sm">
        <div class="card-body p-4">
            <h5 class="fw-bold">Kehadiran Hari Ini</h5>
            <p class="text-secondary"><?= h(date('d-m-Y')) ?></p>

            <p>
                Jam masuk:
                <strong>
                    <?= h($dataHariIni['jam_masuk'] ?? '-') ?>
                </strong>
            </p>
            <p>
                Jam pulang:
                <strong>
                    <?= h($dataHariIni['jam_pulang'] ?? '-') ?>
                </strong>
            </p>
            <p>
                Status:
                <strong>
                    <?= h($dataHariIni['status'] ?? 'Belum absen') ?>
                </strong>
            </p>

            <form method="POST" class="d-flex gap-2 flex-wrap">
                <input type="hidden" name="csrf_token"
                       value="<?= h($_SESSION['csrf_absensi_karyawan']) ?>">

                <?php if (!$dataHariIni): ?>
                    <button type="submit" name="aksi" value="masuk"
                            class="btn btn-success">
                        Catat Jam Masuk
                    </button>
                <?php elseif (empty($dataHariIni['jam_pulang'])): ?>
                    <button type="submit" name="aksi" value="pulang"
                            class="btn btn-warning">
                        Catat Jam Pulang
                    </button>
                <?php else: ?>
                    <span class="badge text-bg-success p-2">
                        Absensi hari ini selesai
                    </span>
                <?php endif; ?>

                <a href="dashboard_karyawan.php"
                   class="btn btn-outline-primary">
                    Kembali ke Dashboard
                </a>
            </form>
        </div>
    </div>
</div>
</body>
</html>
