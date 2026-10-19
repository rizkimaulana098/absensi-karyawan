
<?php
session_start();
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

/* Token keamanan formulir */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pesan = '';
$error = '';

/* Proses persetujuan atau penolakan */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Permintaan tidak valid. Silakan muat ulang halaman.';
    } else {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $aksi = $_POST['aksi'] ?? '';
        $catatan = trim($_POST['catatan_admin'] ?? '');

        if (!$id || !in_array($aksi, ['Disetujui', 'Ditolak'], true)) {
            $error = 'Data pengajuan tidak valid.';
        } else {
            /*
             * Hanya pengajuan yang masih Menunggu yang dapat diproses.
             */
            $stmt = $pdo->prepare(
                "UPDATE pengajuan_izin
                 SET status = ?, catatan_admin = ?
                 WHERE id = ? AND status = 'Menunggu'"
            );

            $stmt->execute([$aksi, $catatan, $id]);

            if ($stmt->rowCount() > 0) {
                header('Location: admin_pengajuan.php?hasil=' . urlencode($aksi));
                exit;
            } else {
                $error = 'Pengajuan tidak ditemukan atau sudah diproses.';
            }
        }
    }
}

if (isset($_GET['hasil'])) {
    if ($_GET['hasil'] === 'Disetujui') {
        $pesan = 'Pengajuan berhasil disetujui.';
    } elseif ($_GET['hasil'] === 'Ditolak') {
        $pesan = 'Pengajuan berhasil ditolak.';
    }
}

/* Filter status */
$filter = $_GET['status'] ?? 'Semua';
$statusValid = ['Semua', 'Menunggu', 'Disetujui', 'Ditolak'];

if (!in_array($filter, $statusValid, true)) {
    $filter = 'Semua';
}

/* Mengambil data pengajuan dan nama karyawan */
$sql = "SELECT
            p.id,
            p.jenis,
            p.tanggal_mulai,
            p.tanggal_selesai,
            p.alasan,
            p.status,
            p.catatan_admin,
            p.created_at,
            k.nip,
            k.nama,
            k.departemen
        FROM pengajuan_izin p
        JOIN karyawan k ON k.id = p.karyawan_id";

$params = [];

if ($filter !== 'Semua') {
    $sql .= " WHERE p.status = ?";
    $params[] = $filter;
}

$sql .= " ORDER BY
            CASE WHEN p.status = 'Menunggu' THEN 0 ELSE 1 END,
            p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pengajuan = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Menghitung jumlah setiap status */
$jumlah = [
    'Menunggu' => 0,
    'Disetujui' => 0,
    'Ditolak' => 0
];

$stmt = $pdo->query(
    "SELECT status, COUNT(*) AS total
     FROM pengajuan_izin
     GROUP BY status"
);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($jumlah[$row['status']])) {
        $jumlah[$row['status']] = (int)$row['total'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelola Pengajuan Karyawan</title>

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
        }

        .alasan {
            min-width: 180px;
            max-width: 260px;
            white-space: normal;
            overflow-wrap: anywhere;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
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
            <h3 class="fw-bold mb-1">Kelola Pengajuan</h3>
            <p class="text-secondary mb-0">
                Periksa dan proses pengajuan izin, cuti, dan sakit.
            </p>
        </div>

        <a href="dashboard_admin.php" class="btn btn-outline-primary">
            Kembali ke Dashboard
        </a>
    </div>

    <?php if ($pesan): ?>
        <div class="alert alert-success"><?= h($pesan) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card stat-card shadow-sm">
                <div class="card-body p-4">
                    <div class="text-secondary">Menunggu</div>
                    <div class="fs-2 fw-bold text-warning">
                        <?= $jumlah['Menunggu'] ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card stat-card shadow-sm">
                <div class="card-body p-4">
                    <div class="text-secondary">Disetujui</div>
                    <div class="fs-2 fw-bold text-success">
                        <?= $jumlah['Disetujui'] ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card stat-card shadow-sm">
                <div class="card-body p-4">
                    <div class="text-secondary">Ditolak</div>
                    <div class="fs-2 fw-bold text-danger">
                        <?= $jumlah['Ditolak'] ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card main-card shadow-sm">
        <div class="card-body p-3 p-lg-4">

            <form method="GET" class="row g-2 align-items-end mb-4">
                <div class="col-sm-5 col-md-3">
                    <label class="form-label fw-semibold">Filter Status</label>
                    <select name="status" class="form-select">
                        <?php foreach ($statusValid as $s): ?>
                            <option value="<?= h($s) ?>"
                                <?= $filter === $s ? 'selected' : '' ?>>
                                <?= h($s) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-sm-4 col-md-2">
                    <button class="btn btn-primary w-100" type="submit">
                        Filter
                    </button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>No.</th>
                            <th>Karyawan</th>
                            <th>Jenis</th>
                            <th>Tanggal Pengajuan</th>
                            <th>Alasan</th>
                            <th>Status</th>
                            <th>Catatan Admin</th>
                            <th style="min-width:250px">Tindakan</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$pengajuan): ?>
                        <tr>
                            <td colspan="8" class="text-center text-secondary py-4">
                                Belum ada pengajuan pada filter ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pengajuan as $i => $p): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>

                                <td>
                                    <strong><?= h($p['nama']) ?></strong><br>
                                    <small class="text-secondary">
                                        NIP: <?= h($p['nip']) ?>
                                    </small><br>
                                    <small class="text-secondary">
                                        <?= h($p['departemen'] ?? '-') ?>
                                    </small>
                                </td>

                                <td><?= h($p['jenis']) ?></td>

                                <td>
                                    <?= h($p['tanggal_mulai']) ?><br>
                                    s.d. <?= h($p['tanggal_selesai']) ?>
                                </td>

                                <td class="alasan"><?= nl2br(h($p['alasan'])) ?></td>

                                <td>
                                    <?php
                                    $warna = 'warning';

                                    if ($p['status'] === 'Disetujui') {
                                        $warna = 'success';
                                    } elseif ($p['status'] === 'Ditolak') {
                                        $warna = 'danger';
                                    }
                                    ?>
                                    <span class="badge text-bg-<?= $warna ?>">
                                        <?= h($p['status']) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= h($p['catatan_admin'] ?: '-') ?>
                                </td>

                                <td>
                                    <?php if ($p['status'] === 'Menunggu'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= h($_SESSION['csrf_token']) ?>">

                                            <input type="hidden" name="id"
                                                   value="<?= (int)$p['id'] ?>">

                                            <textarea name="catatan_admin"
                                                      class="form-control form-control-sm mb-2"
                                                      rows="2"
                                                      placeholder="Catatan (opsional)"></textarea>

                                            <div class="d-flex gap-2">
                                                <button type="submit"
                                                        name="aksi"
                                                        value="Disetujui"
                                                        class="btn btn-success btn-sm"
                                                        onclick="return confirm('Setujui pengajuan ini?')">
                                                    Setujui
                                                </button>

                                                <button type="submit"
                                                        name="aksi"
                                                        value="Ditolak"
                                                        class="btn btn-danger btn-sm"
                                                        onclick="return confirm('Tolak pengajuan ini?')">
                                                    Tolak
                                                </button>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-secondary small">
                                            Sudah diproses
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

    <p class="text-center text-secondary small mt-4">
        AbsensiKita &copy; <?= date('Y') ?>
    </p>
</div>

</body>
</html>
