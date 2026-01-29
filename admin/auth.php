<?php
// admin/auth.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
  // ปรับ cookie ให้ปลอดภัยขึ้น
  session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    // 'secure' => true, // เปิดเมื่อใช้ HTTPS เท่านั้น
  ]);
  session_start();
}

function require_login(): void {
  if (empty($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
  }
}

function current_admin(): ?array {
  return $_SESSION['admin'] ?? null;
}

// ถ้าต้องการจำกัด role
function require_role(array $allowedRoles): void {
  require_login();
  $admin = current_admin();
  $role = $admin['role'] ?? '';
  if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    echo "Forbidden";
    exit;
  }
}
