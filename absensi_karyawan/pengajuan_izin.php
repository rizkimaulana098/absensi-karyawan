
<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] !== 'karyawan') {
    exit('Halaman ini khusus karyawan.');
}

$karyawanId = (int)($_SESSION['karyawan_id'] ?? 0);
$pesan = '';
$error = '';

if ($karyawanId <= 0) {
    exit(
        'ID karyawan belum tersimpan di session login. ' .
        'Perlu menyesuaikan pengambilan ID karyawan dengan kode login kamu.'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jenis = $_POST['jenis'] ?? '';
    $mulai = $_POST['tanggal_mulai'] ?? '';
    $selesai = $_POST['tanggal_selesai'] ?? '';
    $alasan = trim($_POST['alasan'] ?? '');

    $jenisValid = ['Izin', 'Cuti', 'Sakit'];
    $tanggalValid = false;

    $d1 = DateTime::createFromFormat('!Y-m-d', $mulai);
    $d2 = DateTime::createFromFormat('!Y-m-d', $selesai);

    if (
        $d1 && $d1->format('Y-m-d') === $mulai &&
        $d2 && $d2->format('Y-m-d') === $selesai
    ) {
        $tanggalValid = true;
    }

    if (!in_array($jenis, $jenisValid, true)) {
        $error = 'Jenis pengajuan tidak valid.';
    } elseif (!$tanggalValid || $selesai < $mulai) {
        $error = 'Tanggal pengajuan tidak valid.';
    } elseif ($alasan === '') {
        $error = 'Alasan pengajuan wajib diisi.';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO pengajuan_izin
             (karyawan_id, jenis, tanggal_mulai, tanggal_selesai, alasan)
             VALUES (?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $karyawanId,
            $jenis,
            $mulai,
            $selesai,
            $alasan
        ]);

        header('Location: pengajuan_izin.php?berhasil=1');
        exit;
    }
}

if (isset($_GET['berhasil'])) {
    $pesan = 'Pengajuan berhasil dikirim dan menunggu persetujuan Admin.';
}

$stmt = $pdo->prepare(
    "SELECT * FROM pengajuan_izin
     WHERE karyawan_id = ?
     ORDER BY created_at DESC"
);
$stmt->execute([$karyawanId]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pengajuan Izin dan Cuti</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
    <style>
        body { background: #f3f6fb; }
        .card { border: 0; border-radius: 16px; }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold">Pengajuan Izin dan Cuti</h3>
            <p class="text-secondary mb-0">Kirim pengajuan dan pantau statusnya.</p>
        </div>
        <a href="dashboard_karyawan.php" class="btn btn-outline-primary">
            Kembali
        </a>
    </div>

    <?php if ($pesan): ?>
        <div class="alert alert-success"><?= h($pesan) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-3">Form Pengajuan</h5>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Jenis Pengajuan</label>
                    <select name="jenis" class="form-select" required>
                        <option value="">-- Pilih jenis --</option>
                        <option value="Izin">Izin</option>
                        <option value="Cuti">Cuti</option>
                        <option value="Sakit">Sakit</option>
                    </select>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tanggal Mulai</label>
                        <input type="date" name="tanggal_mulai"
                               class="form-control" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tanggal Selesai</label>
                        <input type="date" name="tanggal_selesai"
                               class="form-control" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Alasan</label>
                    <textarea name="alasan" class="form-control"
                              rows="3" required
                              placeholder="Jelaskan alasan pengajuan..."></textarea>
                </div>

                <button type="submit" class="btn btn-primary">
                    Kirim Pengajuan
                </button>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-3">Riwayat Pengajuan Saya</h5>

            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Jenis</th>
                            <th>Mulai</th>
                            <th>Selesai</th>
                            <th>Alasan</th>
                            <th>Status</th>
                            <th>Catatan Admin</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$data): ?>
                        <tr>
                            <td colspan="6" class="text-center">
                                Belum ada pengajuan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($data as $d): ?>
                            <tr>
                                <td><?= h($d['jenis']) ?></td>
                                <td><?= h($d['tanggal_mulai']) ?></td>
                                <td><?= h($d['tanggal_selesai']) ?></td>
                                <td><?= h($d['alasan']) ?></td>
                                <td>
                                    <?php
                                    $warna = 'warning';
                                    if ($d['status'] === 'Disetujui') {
                                        $warna = 'success';
                                    } elseif ($d['status'] === 'Ditolak') {
                                        $warna = 'danger';
                                    }
                                    ?>
                                    <span class="badge text-bg-<?= $warna ?>">
                                        <?= h($d['status']) ?>
                                    </span>
                                </td>
                                <td><?= h($d['catatan_admin'] ?? '-') ?></td>
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
