
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

/* Ambil data karyawan yang sedang login */
$stmt = $pdo->prepare(
    "SELECT k.id, k.nama, k.nip
     FROM users u
     JOIN karyawan k ON k.id = u.karyawan_id
     WHERE u.id = ? AND u.role = 'karyawan'
       AND k.status = 'Aktif'
     LIMIT 1"
);
$stmt->execute([$_SESSION['user_id']]);
$karyawan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$karyawan) {
    exit('Data karyawan tidak ditemukan atau tidak aktif. Hubungi Admin.');
}

$karyawanId = (int)$karyawan['id'];
$pesan = '';
$jenisPesan = 'success';

/* Simpan selfie JPEG dari kamera browser */
function simpanSelfie($dataUrl) {
    if (!is_string($dataUrl) || strlen($dataUrl) > 7 * 1024 * 1024) {
        throw new RuntimeException(
            'Ukuran foto terlalu besar. Silakan ambil ulang foto.'
        );
    }

    if (!preg_match(
        '/^data:image\/jpeg;base64,([A-Za-z0-9+\/=]+)$/',
        $dataUrl,
        $matches
    )) {
        throw new RuntimeException(
            'Foto selfie belum tersedia. Ambil foto menggunakan kamera.'
        );
    }

    $binary = base64_decode($matches[1], true);

    if ($binary === false || strlen($binary) > 5 * 1024 * 1024) {
        throw new RuntimeException('Ukuran foto maksimal 5 MB.');
    }

    $info = getimagesizefromstring($binary);

    if (!$info || $info['mime'] !== 'image/jpeg') {
        throw new RuntimeException('Format foto tidak valid.');
    }

    $folder = __DIR__ . '/uploads/absensi/';

    if (!is_dir($folder) &&
        !mkdir($folder, 0755, true) &&
        !is_dir($folder)) {
        throw new RuntimeException(
            'Folder foto tidak dapat dibuat. Periksa folder uploads.'
        );
    }

    $namaFile = 'selfie_' . bin2hex(random_bytes(16)) . '.jpg';
    $path = $folder . $namaFile;

    if (file_put_contents($path, $binary, LOCK_EX) === false) {
        throw new RuntimeException(
            'Foto gagal disimpan. Periksa izin folder uploads/absensi.'
        );
    }

    return 'uploads/absensi/' . $namaFile;
}

/* Validasi koordinat GPS */
function validasiKoordinat($latitude, $longitude) {
    if (
        $latitude === null || $longitude === null ||
        $latitude === '' || $longitude === '' ||
        !is_numeric($latitude) || !is_numeric($longitude)
    ) {
        throw new RuntimeException(
            'Lokasi GPS belum tersedia. Aktifkan lokasi dan coba lagi.'
        );
    }

    $lat = (float)$latitude;
    $lng = (float)$longitude;

    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        throw new RuntimeException('Koordinat GPS tidak valid.');
    }

    return [$lat, $lng];
}

/* Proses tombol masuk dan pulang */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fotoPath = null;

    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals(
                $_SESSION['csrf_absensi_karyawan'],
                (string)$_POST['csrf_token']
            )
        ) {
            throw new RuntimeException(
                'Permintaan tidak valid. Muat ulang halaman.'
            );
        }

        $aksi = $_POST['aksi'] ?? '';
        $tanggal = date('Y-m-d');
        $jam = date('H:i:s');

        if (!in_array($aksi, ['masuk', 'pulang'], true)) {
            throw new RuntimeException('Tindakan absensi tidak valid.');
        }

        [$latitude, $longitude] = validasiKoordinat(
            $_POST['latitude'] ?? null,
            $_POST['longitude'] ?? null
        );

        $fotoData = $_POST['foto_base64'] ?? '';

        if ($aksi === 'masuk') {
            /* Cek apakah karyawan sudah absen hari ini */
            $stmt = $pdo->prepare(
                "SELECT id FROM absensi
                 WHERE karyawan_id = ? AND tanggal = ?
                 LIMIT 1"
            );
            $stmt->execute([$karyawanId, $tanggal]);

            if ($stmt->fetch()) {
                throw new RuntimeException(
                    'Absensi masuk hari ini sudah tercatat.'
                );
            }

            $fotoPath = simpanSelfie($fotoData);
            $status = ($jam > '08:00:00') ? 'Terlambat' : 'Hadir';

            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO absensi
                    (karyawan_id, tanggal, jam_masuk, status,
                     foto_masuk, latitude_masuk, longitude_masuk)
                    VALUES (?, ?, ?, ?, ?, ?, ?)"
                );

                $stmt->execute([
                    $karyawanId,
                    $tanggal,
                    $jam,
                    $status,
                    $fotoPath,
                    $latitude,
                    $longitude
                ]);
            } catch (Throwable $e) {
                if (is_file(__DIR__ . '/' . $fotoPath)) {
                    unlink(__DIR__ . '/' . $fotoPath);
                }
                throw $e;
            }

            $pesan = 'Absensi masuk berhasil! Status: ' . $status . '.';

        } else {
            /* Pastikan sudah masuk dan belum absen pulang */
            $stmt = $pdo->prepare(
                "SELECT id FROM absensi
                 WHERE karyawan_id = ? AND tanggal = ?
                   AND jam_masuk IS NOT NULL
                   AND jam_pulang IS NULL
                 LIMIT 1"
            );
            $stmt->execute([$karyawanId, $tanggal]);
            $dataMasuk = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$dataMasuk) {
                throw new RuntimeException(
                    'Jam masuk belum tercatat atau absensi pulang sudah dilakukan.'
                );
            }

            $fotoPath = simpanSelfie($fotoData);

            $stmt = $pdo->prepare(
                "UPDATE absensi
                 SET jam_pulang = ?, foto_pulang = ?,
                     latitude_pulang = ?, longitude_pulang = ?
                 WHERE id = ? AND jam_pulang IS NULL"
            );

            $stmt->execute([
                $jam,
                $fotoPath,
                $latitude,
                $longitude,
                $dataMasuk['id']
            ]);

            if ($stmt->rowCount() === 0) {
                if (is_file(__DIR__ . '/' . $fotoPath)) {
                    unlink(__DIR__ . '/' . $fotoPath);
                }

                throw new RuntimeException(
                    'Absensi pulang gagal. Silakan muat ulang halaman.'
                );
            }

            $pesan = 'Absensi pulang berhasil!';
        }

    } catch (RuntimeException $e) {
        $pesan = $e->getMessage();
        $jenisPesan = 'danger';

    } catch (Throwable $e) {
        error_log($e->getMessage());
        $pesan = 'Terjadi kesalahan saat menyimpan absensi. Periksa struktur database.';
        $jenisPesan = 'danger';
    }
}

/* Ambil status absensi hari ini */
$stmt = $pdo->prepare(
    "SELECT jam_masuk, jam_pulang, status,
            foto_masuk, foto_pulang,
            latitude_masuk, longitude_masuk,
            latitude_pulang, longitude_pulang
     FROM absensi
     WHERE karyawan_id = ? AND tanggal = ?
     LIMIT 1"
);
$stmt->execute([$karyawanId, date('Y-m-d')]);
$dataHariIni = $stmt->fetch(PDO::FETCH_ASSOC);
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
        body {
            background: #f3f6fb;
            min-height: 100vh;
        }
        .navbar {
            background: linear-gradient(100deg, #1746a2, #3677ed);
        }
        .main-card {
            border: 0;
            border-radius: 18px;
        }
        .camera-box {
            background: #101828;
            border-radius: 14px;
            overflow: hidden;
            min-height: 220px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #video, #preview {
            width: 100%;
            max-height: 380px;
            object-fit: cover;
        }
        #preview {
            display: none;
        }
        .status-card {
            border-radius: 12px;
            background: #f7f9fc;
            padding: 15px;
        }
    </style>
</head>

<body>
<nav class="navbar navbar-dark">
    <div class="container">
        <a href="dashboard_karyawan.php" class="navbar-brand fw-bold">
            AbsensiKita
        </a>
        <a href="logout.php" class="btn btn-light btn-sm">Logout</a>
    </div>
</nav>

<div class="container py-4">
    <div class="mb-4">
        <h3 class="fw-bold mb-1">Absensi Saya</h3>
        <p class="text-secondary mb-0">
            <?= h($karyawan['nama']) ?> · NIP <?= h($karyawan['nip']) ?>
        </p>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-<?= h($jenisPesan) ?> alert-dismissible fade show">
            <?= h($pesan) ?>
            <button type="button" class="btn-close"
                    data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card main-card shadow-sm">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-1">Foto Selfie Absensi</h5>
                    <p class="text-secondary small">
                        Aktifkan kamera, lalu ambil foto wajah sebelum mengirim absensi.
                    </p>

                    <div class="camera-box mb-3">
                        <video id="video" autoplay playsinline></video>
                        <img id="preview" alt="Pratinjau selfie">
                        <canvas id="canvas" class="d-none"></canvas>
                    </div>

                    <div class="d-flex gap-2 flex-wrap mb-4">
                        <button type="button" id="btnKamera"
                                class="btn btn-outline-primary">
                            Aktifkan Kamera
                        </button>
                        <button type="button" id="btnFoto"
                                class="btn btn-primary" disabled>
                            Ambil Selfie
                        </button>
                        <button type="button" id="btnUlang"
                                class="btn btn-outline-secondary" disabled>
                            Ulangi Foto
                        </button>
                    </div>

                    <h5 class="fw-bold mb-1">Lokasi GPS</h5>
                    <p class="text-secondary small">
                        Aktifkan lokasi perangkat untuk merekam koordinat absensi.
                    </p>

                    <button type="button" id="btnGPS"
                            class="btn btn-outline-success mb-2">
                        Ambil Lokasi GPS
                    </button>

                    <div id="gpsStatus" class="small text-secondary mb-3">
                        Lokasi belum diambil.
                    </div>

                    <form method="POST" id="formAbsensi">
                        <input type="hidden" name="csrf_token"
                               value="<?= h($_SESSION['csrf_absensi_karyawan']) ?>">
                        <input type="hidden" name="foto_base64" id="foto_base64">
                        <input type="hidden" name="latitude" id="latitude">
                        <input type="hidden" name="longitude" id="longitude">

                        <?php if (!$dataHariIni): ?>
                            <button type="submit" name="aksi" value="masuk"
                                    class="btn btn-success btn-lg w-100">
                                Catat Jam Masuk
                            </button>
                        <?php elseif (empty($dataHariIni['jam_pulang'])): ?>
                            <button type="submit" name="aksi" value="pulang"
                                    class="btn btn-warning btn-lg w-100">
                                Catat Jam Pulang
                            </button>
                        <?php else: ?>
                            <div class="alert alert-success mb-0">
                                Absensi hari ini sudah selesai.
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card main-card shadow-sm">
                <div class="card-body p-4">
                    <h5 class="fw-bold">Kehadiran Hari Ini</h5>
                    <p class="text-secondary">
                        <?= h(date('d-m-Y')) ?>
                    </p>

                    <div class="status-card mb-3">
                        <div class="text-secondary small">Jam Masuk</div>
                        <div class="fs-5 fw-bold">
                            <?= h($dataHariIni['jam_masuk'] ?? '-') ?>
                        </div>
                    </div>

                    <div class="status-card mb-3">
                        <div class="text-secondary small">Jam Pulang</div>
                        <div class="fs-5 fw-bold">
                            <?= h($dataHariIni['jam_pulang'] ?? '-') ?>
                        </div>
                    </div>

                    <div class="status-card mb-3">
                        <div class="text-secondary small">Status</div>
                        <div class="fs-5 fw-bold">
                            <?= h($dataHariIni['status'] ?? 'Belum absen') ?>
                        </div>
                    </div>

                    <div class="status-card mb-3">
                        <div class="text-secondary small">Lokasi Masuk</div>
                        <?php if (!empty($dataHariIni['latitude_masuk'])): ?>
                            <a target="_blank" rel="noopener"
                               href="https://www.google.com/maps?q=<?= rawurlencode($dataHariIni['latitude_masuk'] . ',' . $dataHariIni['longitude_masuk']) ?>">
                                Lihat di Google Maps
                            </a>
                        <?php else: ?>
                            <span>-</span>
                        <?php endif; ?>
                    </div>

                    <div class="status-card mb-3">
                        <div class="text-secondary small">Lokasi Pulang</div>
                        <?php if (!empty($dataHariIni['latitude_pulang'])): ?>
                            <a target="_blank" rel="noopener"
                               href="https://www.google.com/maps?q=<?= rawurlencode($dataHariIni['latitude_pulang'] . ',' . $dataHariIni['longitude_pulang']) ?>">
                                Lihat di Google Maps
                            </a>
                        <?php else: ?>
                            <span>-</span>
                        <?php endif; ?>
                    </div>

                    <a href="dashboard_karyawan.php"
                       class="btn btn-outline-primary w-100">
                        Kembali ke Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const video = document.getElementById('video');
const canvas = document.getElementById('canvas');
const preview = document.getElementById('preview');
const btnKamera = document.getElementById('btnKamera');
const btnFoto = document.getElementById('btnFoto');
const btnUlang = document.getElementById('btnUlang');
const btnGPS = document.getElementById('btnGPS');

let cameraStream = null;

btnKamera.addEventListener('click', async function () {
    try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('Browser tidak mendukung kamera atau halaman bukan koneksi aman.');
        }

        if (cameraStream) {
            cameraStream.getTracks().forEach(track => track.stop());
        }

        cameraStream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: 'user',
                width: { ideal: 1280 },
                height: { ideal: 720 }
            },
            audio: false
        });

        video.srcObject = cameraStream;
        video.style.display = 'block';
        preview.style.display = 'none';
        btnFoto.disabled = false;
    } catch (error) {
        alert('Kamera tidak dapat dibuka. Izinkan akses kamera pada browser dan coba lagi.');
        console.error(error);
    }
});

btnFoto.addEventListener('click', function () {
    if (!video.videoWidth || !video.videoHeight) {
        alert('Kamera belum siap. Tunggu beberapa detik.');
        return;
    }

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;

    const context = canvas.getContext('2d');
    context.drawImage(video, 0, 0, canvas.width, canvas.height);

    const foto = canvas.toDataURL('image/jpeg', 0.85);
    document.getElementById('foto_base64').value = foto;

    preview.src = foto;
    preview.style.display = 'block';
    video.style.display = 'none';
    btnUlang.disabled = false;

    if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
        cameraStream = null;
    }

    btnFoto.disabled = true;
});

btnUlang.addEventListener('click', function () {
    document.getElementById('foto_base64').value = '';
    preview.src = '';
    preview.style.display = 'none';
    video.style.display = 'block';
    btnUlang.disabled = true;
    btnFoto.disabled = true;
    btnKamera.click();
});

btnGPS.addEventListener('click', function () {
    const gpsStatus = document.getElementById('gpsStatus');

    if (!navigator.geolocation) {
        gpsStatus.textContent = 'Browser tidak mendukung GPS.';
        return;
    }

    gpsStatus.textContent = 'Sedang mengambil lokasi...';
    btnGPS.disabled = true;

    navigator.geolocation.getCurrentPosition(
        function (position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;

            document.getElementById('latitude').value = lat;
            document.getElementById('longitude').value = lng;

            gpsStatus.textContent =
                'Lokasi berhasil diambil: ' +
                lat.toFixed(6) + ', ' + lng.toFixed(6);

            btnGPS.disabled = false;
        },
        function (error) {
            let pesan = 'Lokasi gagal diambil.';

            if (error.code === 1) {
                pesan = 'Izin lokasi ditolak. Izinkan akses lokasi di browser.';
            } else if (error.code === 2) {
                pesan = 'Lokasi tidak tersedia. Periksa GPS atau jaringan.';
            } else if (error.code === 3) {
                pesan = 'Pengambilan lokasi terlalu lama. Coba lagi.';
            }

            gpsStatus.textContent = pesan;
            btnGPS.disabled = false;
        },
        {
            enableHighAccuracy: true,
            timeout: 20000,
            maximumAge: 0
        }
    );
});

document.getElementById('formAbsensi').addEventListener('submit', function (event) {
    const foto = document.getElementById('foto_base64').value;
    const lat = document.getElementById('latitude').value;
    const lng = document.getElementById('longitude').value;

    if (!foto) {
        event.preventDefault();
        alert('Silakan aktifkan kamera dan ambil selfie terlebih dahulu.');
        return;
    }

    if (!lat || !lng) {
        event.preventDefault();
        alert('Silakan ambil lokasi GPS terlebih dahulu.');
    }
});

window.addEventListener('beforeunload', function () {
    if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
    }
});
</script>
</body>
</html>
