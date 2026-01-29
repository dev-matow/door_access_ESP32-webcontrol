<?php
// admin/employees.php
declare(strict_types=1);

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/auth.php";
require_login();

$q = trim((string)($_GET['q'] ?? ''));

$sql = "SELECT employee_id, emp_code, first_name, last_name, department, position, status
        FROM employees WHERE 1=1 ";
$params = [];
if ($q !== '') {
  $sql .= " AND (emp_code LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR department LIKE ?) ";
  $like = "%$q%";
  $params = [$like,$like,$like,$like];
}
$sql .= " ORDER BY first_name, last_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Employees</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <span class="navbar-brand">Employees</span>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-light btn-sm" href="dashboard.php">Dashboard</a>
      <a class="btn btn-outline-light btn-sm" href="logout.php">Logout</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">จัดการพนักงาน</h4>
    <a class="btn btn-primary" href="employee_form.php">+ เพิ่มพนักงาน</a>
  </div>

  <form class="row g-2 mb-3" method="get">
    <div class="col-12 col-lg-6">
      <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="ค้นหา รหัส/ชื่อ/แผนก">
    </div>
    <div class="col-6 col-lg-3">
      <button class="btn btn-outline-primary w-100">ค้นหา</button>
    </div>
    <div class="col-6 col-lg-3">
      <a class="btn btn-outline-secondary w-100" href="employees.php">ล้าง</a>
    </div>
  </form>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>ชื่อ</th>
              <th>รหัส</th>
              <th>แผนก</th>
              <th>ตำแหน่ง</th>
              <th>Status</th>
              <th class="text-end">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="fw-semibold"><?= htmlspecialchars($r['first_name']." ".$r['last_name']) ?></td>
                <td><?= htmlspecialchars((string)$r['emp_code']) ?></td>
                <td><?= htmlspecialchars((string)$r['department']) ?></td>
                <td><?= htmlspecialchars((string)$r['position']) ?></td>
                <td><?= htmlspecialchars($r['status']) ?></td>
                <td class="text-end">
                  <a class="btn btn-outline-secondary btn-sm" href="employee_form.php?employee_id=<?= (int)$r['employee_id'] ?>">แก้ไข</a>
                  <a class="btn btn-warning btn-sm" href="permissions.php?employee_id=<?= (int)$r['employee_id'] ?>">กำหนดสิทธิ์</a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body>
</html>
