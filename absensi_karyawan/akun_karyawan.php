
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/koneksi.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_akun'])) {
    $_SESSION['csrf_akun'] = bin2hex(random_bytes(32));
}

$pesan = '';
$jenisPesan = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_akun'], $_POST['csrf_token'])
        ) {
            throw new RuntimeException(
                'Permintaan tidak valid. Muat ulang halaman.'
            );
        }

        $karyawanId = filter_input(
            INPUT_POST,
            'karyawan_id',
            FILTER_VALIDATE_INT
        );

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$karyawanId) {
            throw new RuntimeException('Pilih karyawan terlebih dahulu.');
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]{4,30}$/', $username)) {
            throw new RuntimeException(
                'Username harus 4-30 karakter dan hanya boleh menggunakan huruf, angka, titik, garis bawah, atau tanda hubung.'
            );
        }

        if (strlen($password) < 8) {
            throw new RuntimeException(
                'Password minimal 8 karakter.'
            );
        }

        // Periksa karyawan aktif.
        $stmt = $pdo->prepare(
            "SELECT id FROM karyawan
             WHERE id = ? AND status = 'Aktif'"
        );
        $stmt->execute([$karyawanId]);

        if (!$stmt->fetch()) {
            throw new RuntimeException(
                'Karyawan tidak ditemukan atau tidak aktif.'
            );
        }

        // Periksa apakah karyawan sudah memiliki akun.
        $stmt = $pdo->prepare(
            "SELECT id FROM users WHERE karyawan_id = ?"
        );
        $stmt->execute([$karyawanId]);

        if ($stmt->fetch()) {
            throw new RuntimeException(
                'Karyawan ini sudah memiliki akun login.'
            );
        }

        // Buat akun karyawan.
        $stmt = $pdo->prepare(
            "INSERT INTO users
                (karyawan_id, username, password, role)
             VALUES (?, ?, ?, 'karyawan')"
        );

        $stmt->execute([
            $karyawanId,
            $username,
            password_hash($password, PASSWORD_DEFAULT)
        ]);

        $pesan = 'Akun karyawan berhasil dibuat.';

    } catch (RuntimeException $e) {
        $pesan = $e->getMessage();
        $jenisPesan = 'danger';

    } catch (PDOException $e) {
        if (
            isset($e->errorInfo[1]) &&
            (int)$e->errorInfo[1] === 1062
        ) {
            $pesan = 'Username sudah digunakan. Gunakan username lain.';
        } else {
            error_log($e->getMessage());
            $pesan = 'Gagal menyimpan akun. Periksa struktur tabel users.';
        }

        $jenisPesan = 'danger';
    }
}

// Ambil karyawan aktif yang belum mempunyai akun.
$stmt = $pdo->query(
    "SELECT k.id, k.nip, k.nama, k.departemen
     FROM karyawan k
     LEFT JOIN users u ON u.karyawan_id = k.id
     WHERE k.status = 'Aktif'
       AND u.id IS NULL
     ORDER BY k.nama ASC"
);

$karyawanBelumPunyaAkun = $stmt->fetchAll();

// Ambil daftar akun karyawan yang sudah dibuat.
$stmt = $pdo->query(
    "SELECT u.username, k.nip, k.nama, k.departemen
     FROM users u
     INNER JOIN karyawan k ON k.id = u.karyawan_id
     WHERE u.role = 'karyawan'
     ORDER BY k.nama ASC"
);

$daftarAkun = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Akun Karyawan - AbsensiKita</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            background: #f4f7fb;
        }

        .main-card {
            border: none;
            border-radius: 16px;
        }

        .page-title {
            color: #172554;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
    <div class="container">
        <a href="dashboard.php" class="navbar-brand fw-bold">
            AbsensiKita
        </a>

        <a href="logout.php" class="btn btn-light btn-sm">
            Logout
        </a>
    </div>
</nav>

<div class="container py-4">

    <h3 class="page-title fw-bold">Manajemen Akun Karyawan</h3>

    <p class="text-secondary">
        Admin dapat membuat akun login untuk karyawan yang terdaftar.
    </p>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-<?= h($jenisPesan) ?> alert-dismissible fade show">
            <?= h($pesan) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>
        </div>
    <?php endif; ?>

    <div class="card main-card shadow-sm mb-4">
        <div class="card-body p-4">

            <h5 class="fw-bold mb-3">Buat Akun Baru</h5>

            <?php if (empty($karyawanBelumPunyaAkun)): ?>

                <div class="alert alert-info mb-0">
                    Semua karyawan aktif sudah mempunyai akun,
                    atau belum ada data karyawan aktif.
                    Periksa tabel data karyawan.
                </div>

            <?php else: ?>

                <form method="POST" autocomplete="off">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= h($_SESSION['csrf_akun']) ?>"
                    >

                    <div class="mb-3">
                        <label for="karyawan_id" class="form-label">
                            Pilih Karyawan
                        </label>

                        <select
                            name="karyawan_id"
                            id="karyawan_id"
                            class="form-select"
                            required
                        >
                            <option value="">
                                -- Pilih karyawan --
                            </option>

                            <?php foreach ($karyawanBelumPunyaAkun as $k): ?>
                                <option value="<?= (int)$k['id'] ?>">
                                    <?= h($k['nip']) ?> -
                                    <?= h($k['nama']) ?> -
                                    <?= h($k['departemen']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="username" class="form-label">
                            Username
                        </label>

                        <input
                            type="text"
                            name="username"
                            id="username"
                            class="form-control"
                            minlength="4"
                            maxlength="30"
                            pattern="[a-zA-Z0-9_.-]+"
                            placeholder="Contoh: budi001"
                            autocomplete="off"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">
                            Password
                        </label>

                        <input
                            type="password"
                            name="password"
                            id="password"
                            class="form-control"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >

                        <div class="form-text">
                            Password minimal 8 karakter.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        Buat Akun Karyawan
                    </button>

                    <a
                        href="dashboard.php"
                        class="btn btn-outline-secondary"
                    >
                        Kembali
                    </a>

                </form>

            <?php endif; ?>

        </div>
    </div>

    <div class="card main-card shadow-sm">
        <div class="card-body p-4">

            <h5 class="fw-bold mb-3">Daftar Akun Karyawan</h5>

            <div class="table-responsive">
                <table class="table table-hover align-middle">

                    <thead class="table-light">
                        <tr>
                            <th>No.</th>
                            <th>NIP</th>
                            <th>Nama Karyawan</th>
                            <th>Departemen</th>
                            <th>Username</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($daftarAkun)): ?>

                        <tr>
                            <td colspan="5" class="text-center text-secondary">
                                Belum ada akun karyawan.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($daftarAkun as $i => $akun): ?>

                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= h($akun['nip']) ?></td>
                                <td><?= h($akun['nama']) ?></td>
                                <td><?= h($akun['departemen']) ?></td>
                                <td><?= h($akun['username']) ?></td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>
            </div>

        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
