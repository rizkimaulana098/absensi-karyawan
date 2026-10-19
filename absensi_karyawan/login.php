
<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';

// Arahkan pengguna sesuai perannya jika sudah login
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: dashboard.php');
        exit;
    }

    if ($_SESSION['role'] === 'karyawan') {
        header('Location: dashboard_karyawan.php');
        exit;
    }

    // Hapus sesi jika peran tidak dikenali
    session_unset();
    session_destroy();
    session_start();
}

$pesan = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare(
        "SELECT id, username, password, role, karyawan_id
         FROM users
         WHERE username = ?
         LIMIT 1"
    );

    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // Pastikan peran akun valid
        if (!in_array($user['role'], ['admin', 'karyawan'], true)) {
            $pesan = 'Peran akun tidak valid.';
        } else {
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // Arahkan ke halaman sesuai peran
            if ($user['role'] === 'admin') {
                header('Location: dashboard.php');
                exit;
            }

            if ($user['role'] === 'karyawan') {
                header('Location: dashboard_karyawan.php');
                exit;
            }
        }
    } else {
        $pesan = 'Username atau password salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Absensi Karyawan</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body class="bg-light">
<div class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
    <div class="card border-0 shadow-sm w-100" style="max-width:430px">
        <div class="card-body p-4 p-md-5">

            <div class="text-center mb-4">
                <div class="fs-1">🕒</div>
                <h2 class="fw-bold">Absensi Karyawan</h2>
                <p class="text-secondary">Silakan masuk ke akun Anda</p>
            </div>

            <?php if ($pesan !== ''): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input
                        type="text"
                        name="username"
                        class="form-control form-control-lg"
                        required
                        autocomplete="username"
                        autofocus
                    >
                </div>

                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <input
                        type="password"
                        name="password"
                        class="form-control form-control-lg"
                        required
                        autocomplete="current-password"
                    >
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100">
                    Masuk
                </button>
            </form>

            <p class="small text-secondary text-center mt-4 mb-0">
                Sistem Informasi Absensi Karyawan
            </p>

        </div>
    </div>
</div>
</body>
</html>
