
<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/config/koneksi.php';

/* Keamanan: hanya Admin yang boleh membuka halaman ini */
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* Filter tanggal */
$tanggal = $_GET['tanggal'] ?? date('Y-m-d');

$dt = DateTime::createFromFormat('!Y-m-d', $tanggal);
if (!$dt || $dt->format('Y-m-d') !== $tanggal) {
    $tanggal = date('Y-m-d');
}

/* Ambil data absensi beserta identitas karyawan */
$sql = "SELECT
            a.id,
            a.tanggal,
            a.jam_masuk,
            a.jam_pulang,
            a.status,
            a.foto_masuk,
            a.foto_pulang,
            a.latitude_masuk,
            a.longitude_masuk,
            a.latitude_pulang,
            a.longitude_pulang,
            k.nama,
            k.nip
        FROM absensi a
        JOIN karyawan k ON k.id = a.karyawan_id
        WHERE a.tanggal = ?
        ORDER BY a.jam_masuk DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$tanggal]);
$dataAbsensi = $stmt->fetchAll(PDO::FETCH_ASSOC);

function urlFoto($foto) {
    if (!$foto || !is_string($foto)) {
        return null;
    }

    /* Hanya menerima path foto yang dihasilkan aplikasi */
    if (!preg_match(
        '#^uploads/absensi/[A-Za-z0-9_-]+\.jpg$#',
        $foto
    )) {
        return null;
    }

    if (!is_file(__DIR__ . '/' . $foto)) {
        return null;
    }

    return $foto;
}

function urlMaps($lat, $lng) {
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return null;
    }

    $lat = (float)$lat;
    $lng = (float)$lng;

    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return null;
    }

    return 'https://www.google.com/maps?q=' .
        rawurlencode($lat . ',' . $lng);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Bukti Absensi Karyawan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        body {
            background: #f3f6fb;
        }

        .navbar {
            background: linear-gradient(100deg, #1746a2, #3677ed);
        }

        .main-card {
            border: 0;
            border-radius: 16px;
        }

        .table th,
        .table td {
            vertical-align: middle;
            white-space: nowrap;
        }

        .foto-absensi {
            width: 85px;
            height: 85px;
            object-fit: cover;
            border-radius: 10px;
            cursor: pointer;
            border: 1px solid #dee2e6;
        }

        .foto-kosong {
            display: inline-flex;
            width: 85px;
            height: 85px;
            align-items: center;
            justify-content: center;
            background: #e9edf4;
            color: #687386;
            border-radius: 10px;
            white-space: normal;
            text-align: center;
            font-size: 12px;
        }

        .table-responsive {
            border-radius: 10px;
        }
    </style>
</head>

<body>
<nav class="navbar navbar-dark">
    <div class="container-fluid px-4">
        <a href="dashboard_admin.php"
           class="navbar-brand fw-bold">
            AbsensiKita · Admin
        </a>

        <a href="logout.php" class="btn btn-light btn-sm">
            Logout
        </a>
    </div>
</nav>

<div class="container-fluid px-3 px-lg-4 py-4">

    <div class="d-flex justify-content-between align-items-center
                flex-wrap gap-2 mb-4">
        <div>
            <h3 class="fw-bold mb-1">Bukti Absensi Karyawan</h3>
            <p class="text-secondary mb-0">
                Foto selfie, waktu kehadiran, dan lokasi GPS.
            </p>
        </div>

        <a href="dashboard.php"
           class="btn btn-outline-primary">
            Kembali ke Dashboard
        </a>
    </div>

    <div class="card main-card shadow-sm mb-4">
        <div class="card-body p-3 p-md-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="tanggal" class="form-label fw-semibold">
                        Tanggal Absensi
                    </label>
                    <input type="date"
                           id="tanggal"
                           name="tanggal"
                           class="form-control"
                           value="<?= h($tanggal) ?>"
                           required>
                </div>

                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        Tampilkan Data
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card main-card shadow-sm">
                <div class="card-body">
                    <div class="text-secondary">Total Data Absensi</div>
                    <div class="fs-3 fw-bold">
                        <?= count($dataAbsensi) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="card main-card shadow-sm">
                <div class="card-body">
                    <div class="text-secondary">Hadir</div>
                    <div class="fs-3 fw-bold text-success">
                        <?php
                        $hadir = 0;
                        foreach ($dataAbsensi as $row) {
                            if ($row['status'] === 'Hadir') {
                                $hadir++;
                            }
                        }
                        echo $hadir;
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="card main-card shadow-sm">
                <div class="card-body">
                    <div class="text-secondary">Terlambat</div>
                    <div class="fs-3 fw-bold text-danger">
                        <?php
                        $terlambat = 0;
                        foreach ($dataAbsensi as $row) {
                            if ($row['status'] === 'Terlambat') {
                                $terlambat++;
                            }
                        }
                        echo $terlambat;
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card main-card shadow-sm">
        <div class="card-body p-3 p-lg-4">
            <h5 class="fw-bold mb-3">
                Data tanggal <?= h(date('d-m-Y', strtotime($tanggal))) ?>
            </h5>

            <?php if (!$dataAbsensi): ?>
                <div class="alert alert-info mb-0">
                    Belum ada data absensi pada tanggal ini.
                </div>
            <?php else: ?>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-primary">
                        <tr>
                            <th>No.</th>
                            <th>Nama Karyawan</th>
                            <th>NIP</th>
                            <th>Jam Masuk</th>
                            <th>Foto Masuk</th>
                            <th>Lokasi Masuk</th>
                            <th>Jam Pulang</th>
                            <th>Foto Pulang</th>
                            <th>Lokasi Pulang</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($dataAbsensi as $i => $row): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= h($row['nama']) ?></td>
                            <td><?= h($row['nip']) ?></td>
                            <td><?= h($row['jam_masuk'] ?? '-') ?></td>

                            <td>
                                <?php $fotoMasuk = urlFoto($row['foto_masuk']); ?>
                                <?php if ($fotoMasuk): ?>
                                    <a href="<?= h($fotoMasuk) ?>"
                                       target="_blank" rel="noopener">
                                        <img class="foto-absensi"
                                             src="<?= h($fotoMasuk) ?>"
                                             alt="Selfie masuk">
                                    </a>
                                <?php else: ?>
                                    <span class="foto-kosong">Foto tidak tersedia</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php
                                $mapsMasuk = urlMaps(
                                    $row['latitude_masuk'],
                                    $row['longitude_masuk']
                                );
                                ?>
                                <?php if ($mapsMasuk): ?>
                                    <a href="<?= h($mapsMasuk) ?>"
                                       target="_blank" rel="noopener"
                                       class="btn btn-sm btn-outline-primary">
                                        Lihat Maps
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>

                            <td><?= h($row['jam_pulang'] ?? '-') ?></td>

                            <td>
                                <?php $fotoPulang = urlFoto($row['foto_pulang']); ?>
                                <?php if ($fotoPulang): ?>
                                    <a href="<?= h($fotoPulang) ?>"
                                       target="_blank" rel="noopener">
                                        <img class="foto-absensi"
                                             src="<?= h($fotoPulang) ?>"
                                             alt="Selfie pulang">
                                    </a>
                                <?php else: ?>
                                    <span class="foto-kosong">Foto tidak tersedia</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php
                                $mapsPulang = urlMaps(
                                    $row['latitude_pulang'],
                                    $row['longitude_pulang']
                                );
                                ?>
                                <?php if ($mapsPulang): ?>
                                    <a href="<?= h($mapsPulang) ?>"
                                       target="_blank" rel="noopener"
                                       class="btn btn-sm btn-outline-primary">
                                        Lihat Maps
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($row['status'] === 'Hadir'): ?>
                                    <span class="badge text-bg-success">
                                        Hadir
                                    </span>
                                <?php elseif ($row['status'] === 'Terlambat'): ?>
                                    <span class="badge text-bg-danger">
                                        Terlambat
                                    </span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">
                                        <?= h($row['status'] ?? '-') ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
