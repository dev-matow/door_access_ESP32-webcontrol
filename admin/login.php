<?php
// admin/login.php
declare(strict_types=1);

require_once __DIR__ . "/../config/db.php";

if (session_status() === PHP_SESSION_NONE) session_start();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim((string)($_POST['username'] ?? ''));
  $password = (string)($_POST['password'] ?? '');

  if ($username === '' || $password === '') {
    $error = "กรุณากรอกชื่อผู้ใช้และรหัสผ่าน";
  } else {
    $stmt = $pdo->prepare("SELECT admin_id, username, password_hash, role FROM admins WHERE username=? LIMIT 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
      $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
    } else {
      // ป้องกัน session fixation
      session_regenerate_id(true);

      $_SESSION['admin'] = [
        'admin_id' => (int)$admin['admin_id'],
        'username' => $admin['username'],
        'role'     => $admin['role'],
      ];

      header("Location: dashboard.php");
      exit;
    }
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-12 col-md-6 col-lg-4">
        <div class="card shadow-sm">
          <div class="card-body p-4">
            <h4 class="mb-3 text-center">Door Access Admin</h4>

            <?php if ($error): ?>
              <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post">
              <div class="mb-3">
                <label class="form-label">Username</label>
                <input class="form-control" name="username" autocomplete="username" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Password</label>
                <input class="form-control" type="password" name="password" autocomplete="current-password" required>
              </div>
              <button class="btn btn-primary w-100" type="submit">เข้าสู่ระบบ</button>
            </form>

            <div class="text-muted small mt-3">
              © Door Access 2026 By dev-matow
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
