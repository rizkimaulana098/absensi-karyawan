
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/koneksi.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_token'];
$pesan = '';
$jenisPesan = 'success';
$editData = null;

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Proses tambah, ubah, dan hapus data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($csrf, $token)) {
        $pesan = 'Permintaan tidak valid. Muat ulang halaman dan coba lagi.';
        $jenisPesan = 'danger';
    } else {
        $aksi = $_POST['aksi'] ?? '';

        try {
            if ($aksi === 'hapus') {
                $id = filter_var(
                    $_POST['id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                if (!$id) {
                    throw new RuntimeException('ID karyawan tidak valid.');
                }

                $stmt = $pdo->prepare(
                    'DELETE FROM karyawan WHERE id = ?'
                );
                $stmt->execute([$id]);

                $pesan = $stmt->rowCount()
                    ? 'Data karyawan berhasil dihapus.'
                    : 'Data karyawan tidak ditemukan.';
            } elseif ($aksi === 'simpan' || $aksi === 'ubah') {
                $id = null;

                if ($aksi === 'ubah') {
                    $id = filter_var(
                        $_POST['id'] ?? null,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1]]
                    );

                    if (!$id) {
                        throw new RuntimeException('ID karyawan tidak valid.');
                    }
                }

                $nip = trim($_POST['nip'] ?? '');
                $nama = trim($_POST['nama'] ?? '');
                $jk = $_POST['jenis_kelamin'] ?? '';
                $departemen = trim($_POST['departemen'] ?? '');
                $jabatan = trim($_POST['jabatan'] ?? '');
                $noHp = trim($_POST['no_hp'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $status = $_POST['status'] ?? 'Aktif';

                if ($nip === '' || $nama === '') {
                    throw new RuntimeException('NIP dan nama wajib diisi.');
                }

                if (strlen($nip) > 30 || strlen($nama) > 100) {
                    throw new RuntimeException('NIP atau nama terlalu panjang.');
                }

                if (!in_array($jk, ['Laki-laki', 'Perempuan'], true)) {
                    throw new RuntimeException('Jenis kelamin tidak valid.');
                }

                if (!in_array($status, ['Aktif', 'Nonaktif'], true)) {
                    throw new RuntimeException('Status karyawan tidak valid.');
                }

                if (
                    strlen($departemen) > 100 ||
                    strlen($jabatan) > 100 ||
                    strlen($noHp) > 20 ||
                    strlen($email) > 100
                ) {
                    throw new RuntimeException('Salah satu kolom terlalu panjang.');
                }

                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Format email tidak valid.');
                }

                $cek = $pdo->prepare(
                    'SELECT id FROM karyawan WHERE nip = ? AND id <> ?'
                );
                $cek->execute([$nip, $id ?? 0]);

                if ($cek->fetch()) {
                    throw new RuntimeException('NIP sudah digunakan.');
                }

                if ($aksi === 'simpan') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO karyawan
                        (nip, nama, jenis_kelamin, departemen, jabatan,
                         no_hp, email, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );

                    $stmt->execute([
                        $nip, $nama, $jk,
                        $departemen ?: null,
                        $jabatan ?: null,
                        $noHp ?: null,
                        $email ?: null,
                        $status
                    ]);

                    $pesan = 'Data karyawan berhasil ditambahkan.';
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE karyawan SET
                        nip = ?, nama = ?, jenis_kelamin = ?,
                        departemen = ?, jabatan = ?, no_hp = ?,
                        email = ?, status = ?
                        WHERE id = ?'
                    );

                    $stmt->execute([
                        $nip, $nama, $jk,
                        $departemen ?: null,
                        $jabatan ?: null,
                        $noHp ?: null,
                        $email ?: null,
                        $status,
                        $id
                    ]);

                    $pesan = 'Data karyawan berhasil diperbarui.';
                }
            } else {
                throw new RuntimeException('Aksi tidak dikenali.');
            }
        } catch (RuntimeException $e) {
            $pesan = $e->getMessage();
            $jenisPesan = 'danger';
        } catch (PDOException $e) {
            error_log($e->getMessage());

            if ($e->getCode() === '23000') {
                $pesan = 'NIP sudah digunakan atau data terkait masih terhubung.';
            } else {
                $pesan = 'Terjadi kesalahan database. Periksa log PHP.';
            }

            $jenisPesan = 'danger';
        }
    }
}

// Ambil data untuk formulir edit
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM karyawan WHERE id = ?');
    $stmt->execute([$editId]);
    $editData = $stmt->fetch();

    if (!$editData) {
        $pesan = 'Data karyawan yang akan diedit tidak ditemukan.';
        $jenisPesan = 'warning';
    }
}

// Pencarian dan daftar karyawan
$cari = trim($_GET['cari'] ?? '');

if ($cari !== '') {
    $stmt = $pdo->prepare(
        'SELECT * FROM karyawan
         WHERE nip LIKE ? OR nama LIKE ? OR departemen LIKE ?
         ORDER BY id DESC'
    );
    $kata = '%' . $cari . '%';
    $stmt->execute([$kata, $kata, $kata]);
    $dataKaryawan = $stmt->fetchAll();
} else {
    $dataKaryawan = $pdo->query(
        'SELECT * FROM karyawan ORDER BY id DESC'
    )->fetchAll();
}

$total = count($dataKaryawan);
$namaAdmin = $_SESSION['username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Karyawan - Absensi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand fw-bold" href="dashboard.php">
            Absensi Karyawan
        </a>
        <div class="d-flex align-items-center gap-3">
            <span class="text-white small">
                Admin: <?= h($namaAdmin) ?>
            </span>
            <a href="logout.php" class="btn btn-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h2 class="fw-bold mb-1">Data Karyawan</h2>
            <p class="text-secondary mb-0">
                Kelola informasi karyawan perusahaan.
            </p>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary">
            Kembali ke Dashboard
        </a>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-<?= h($jenisPesan) ?> alert-dismissible fade show">
            <?= h($pesan) ?>
            <button type="button" class="btn-close"
                    data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">
                        <?= $editData ? 'Edit Karyawan' : 'Tambah Karyawan' ?>
                    </h5>

                    <form method="post" action="karyawan.php<?= $editData ? '?edit=' . (int)$editData['id'] : '' ?>">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="aksi"
                               value="<?= $editData ? 'ubah' : 'simpan' ?>">

                        <?php if ($editData): ?>
                            <input type="hidden" name="id"
                                   value="<?= (int)$editData['id'] ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">NIP *</label>
                            <input type="text" name="nip" class="form-control"
                                   maxlength="30" required
                                   value="<?= h($editData['nip'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Nama Lengkap *</label>
                            <input type="text" name="nama" class="form-control"
                                   maxlength="100" required
                                   value="<?= h($editData['nama'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Jenis Kelamin *</label>
                            <select name="jenis_kelamin" class="form-select" required>
                                <option value="">Pilih jenis kelamin</option>
                                <option value="Laki-laki"
                                    <?= ($editData['jenis_kelamin'] ?? '') === 'Laki-laki' ? 'selected' : '' ?>>
                                    Laki-laki
                                </option>
                                <option value="Perempuan"
                                    <?= ($editData['jenis_kelamin'] ?? '') === 'Perempuan' ? 'selected' : '' ?>>
                                    Perempuan
                                </option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Departemen</label>
                            <input type="text" name="departemen" class="form-control"
                                   maxlength="100"
                                   value="<?= h($editData['departemen'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Jabatan</label>
                            <input type="text" name="jabatan" class="form-control"
                                   maxlength="100"
                                   value="<?= h($editData['jabatan'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Nomor HP</label>
                            <input type="text" name="no_hp" class="form-control"
                                   maxlength="20"
                                   value="<?= h($editData['no_hp'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control"
                                   maxlength="100"
                                   value="<?= h($editData['email'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="Aktif"
                                    <?= ($editData['status'] ?? 'Aktif') === 'Aktif' ? 'selected' : '' ?>>
                                    Aktif
                                </option>
                                <option value="Nonaktif"
                                    <?= ($editData['status'] ?? '') === 'Nonaktif' ? 'selected' : '' ?>>
                                    Nonaktif
                                </option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <?= $editData ? 'Simpan Perubahan' : 'Tambah Karyawan' ?>
                        </button>

                        <?php if ($editData): ?>
                            <a href="karyawan.php"
                               class="btn btn-outline-secondary w-100 mt-2">
                                Batal Edit
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                        <div>
                            <h5 class="fw-bold mb-1">Daftar Karyawan</h5>
                            <span class="text-secondary small">
                                Jumlah hasil: <?= $total ?>
                            </span>
                        </div>

                        <form method="get" class="d-flex gap-2">
                            <input type="search" name="cari"
                                   class="form-control"
                                   placeholder="Cari NIP/nama..."
                                   value="<?= h($cari) ?>">
                            <button class="btn btn-outline-primary">Cari</button>
                            <?php if ($cari !== ''): ?>
                                <a href="karyawan.php"
                                   class="btn btn-outline-secondary">Reset</a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>NIP / Nama</th>
                                    <th>Departemen / Jabatan</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$dataKaryawan): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-secondary py-4">
                                        Belum ada data karyawan.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($dataKaryawan as $k): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($k['nip']) ?></strong><br>
                                            <span><?= h($k['nama']) ?></span>
                                        </td>
                                        <td>
                                            <?= h($k['departemen'] ?: '-') ?><br>
                                            <small class="text-secondary">
                                                <?= h($k['jabatan'] ?: '-') ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge <?= $k['status'] === 'Aktif' ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                                <?= h($k['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <a href="karyawan.php?edit=<?= (int)$k['id'] ?>"
                                                   class="btn btn-sm btn-warning">
                                                    Edit
                                                </a>
                                                <form method="post"
                                                      onsubmit="return confirm('Yakin ingin menghapus data karyawan ini?')">
                                                    <input type="hidden" name="csrf_token"
                                                           value="<?= h($csrf) ?>">
                                                    <input type="hidden" name="aksi" value="hapus">
                                                    <input type="hidden" name="id"
                                                           value="<?= (int)$k['id'] ?>">
                                                    <button type="submit"
                                                            class="btn btn-sm btn-danger">
                                                        Hapus
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
