
<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';

$pesan = '';
$berhasil = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $konfirmasi = $_POST['konfirmasi'] ?? '';

    if (!preg_match('/^[a-zA-Z0-9_.-]{4,50}$/', $username)) {
        $pesan = 'Username harus 4–50 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.';
    } elseif (strlen($password) < 8) {
        $pesan = 'Password minimal 8 karakter.';
    } elseif ($password !== $konfirmasi) {
        $pesan = 'Konfirmasi password tidak sama.';
    } else {
        $cek = $pdo->prepare(
            "SELECT id FROM users WHERE username = ?"
        );
        $cek->execute([$username]);

        if ($cek->fetch()) {
            $pesan = 'Username sudah digunakan. Silakan pilih username lain.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                "INSERT INTO users (karyawan_id, username, password, role)
                 VALUES (NULL, ?, ?, 'admin')"
            );
            $stmt->execute([$username, $hash]);

            $pesan = 'Akun Admin berhasil dibuat. Silakan login.';
            $berhasil = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buat Akun Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="card shadow-sm border-0 mx-auto" style="max-width:480px">
        <div class="card-body p-4">
            <h3 class="mb-2">Buat Akun Admin</h3>
            <p class="text-secondary">Buat akun administrator pertama aplikasi.</p>

            <?php if ($pesan): ?>
                <div class="alert <?= $berhasil ? 'alert-success' : 'alert-danger' ?>">
                    <?= htmlspecialchars($pesan) ?>
                </div>
            <?php endif; ?>

            <?php if (!$berhasil): ?>
            <form method="post">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control"
                           minlength="4" maxlength="50" required
                           autocomplete="username">
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control"
                           minlength="8" required autocomplete="new-password">
                </div>
                <div class="mb-3">
                    <label class="form-label">Konfirmasi Password</label>
                    <input type="password" name="konfirmasi" class="form-control"
                           minlength="8" required autocomplete="new-password">
                </div>
                <button class="btn btn-primary w-100" type="submit">
                    Buat Akun Admin
                </button>
            </form>
            <?php else: ?>
                <a href="login.php" class="btn btn-primary w-100">Ke Halaman Login</a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
