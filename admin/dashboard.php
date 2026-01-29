<?php
// admin/dashboard.php
declare(strict_types=1);

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/auth.php";
require_login();

$admin = current_admin();

// นิยามออนไลน์: last_seen_at ภายใน 2 นาที
$onlineMinutes = 2;

$totalDoors = (int)$pdo->query("SELECT COUNT(*) FROM doors")->fetchColumn();
$onlineDoors = (int)$pdo->query("SELECT COUNT(*) FROM doors WHERE last_seen_at >= (NOW() - INTERVAL {$onlineMinutes} MINUTE) AND status='active'")->fetchColumn();
$offlineDoors = $totalDoors - $onlineDoors;

$todayAllow = (int)$pdo->query("SELECT COUNT(*) FROM access_logs WHERE DATE(ts_server)=CURDATE() AND result='ALLOW'")->fetchColumn();
$todayDeny  = (int)$pdo->query("SELECT COUNT(*) FROM access_logs WHERE DATE(ts_server)=CURDATE() AND result='DENY'")->fetchColumn();

$topDoors = $pdo->query("
  SELECT door_id, COUNT(*) AS cnt
  FROM access_logs
  WHERE DATE(ts_server)=CURDATE()
  GROUP BY door_id
  ORDER BY cnt DESC
  LIMIT 10
")->fetchAll();
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <span class="navbar-brand">Door Access Admin</span>
    <div class="ms-auto d-flex align-items-center gap-2">
      <span class="text-white-50 small"><?= htmlspecialchars($admin['username']) ?> (<?= htmlspecialchars($admin['role']) ?>)</span>
      <a class="btn btn-outline-light btn-sm" href="logout.php">Logout</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <div class="row g-3">
    <div class="col-12 col-lg-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted">ประตูทั้งหมด</div>
          <div class="fs-2 fw-bold"><?= $totalDoors ?></div>
          <div class="small text-muted">นิยามออนไลน์: อัปเดตใน <?= $onlineMinutes ?> นาทีล่าสุด</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted">ออนไลน์</div>
          <div class="fs-2 fw-bold text-success"><?= $onlineDoors ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted">ออฟไลน์</div>
          <div class="fs-2 fw-bold text-secondary"><?= $offlineDoors ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-6">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted">วันนี้ (ALLOW)</div>
          <div class="fs-2 fw-bold"><?= $todayAllow ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-6">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted">วันนี้ (DENY)</div>
          <div class="fs-2 fw-bold"><?= $todayDeny ?></div>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between">
          <strong>Top Doors วันนี้</strong>
          <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="doors.php">จัดการ Doors</a>
            <a class="btn btn-outline-secondary btn-sm" href="employees.php">จัดการ Employees</a>
            <a class="btn btn-primary btn-sm" href="permissions.php">Permissions (ACL)</a>
            <a class="btn btn-outline-secondary btn-sm" href="logs.php">Logs</a>
          </div>
        </div>
        <div class="card-body">
          <?php if (!$topDoors): ?>
            <div class="text-muted">ยังไม่มีการใช้งานวันนี้</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Door ID</th>
                    <th class="text-end">จำนวนครั้ง</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($topDoors as $r): ?>
                    <tr>
                      <td><?= htmlspecialchars($r['door_id']) ?></td>
                      <td class="text-end"><?= (int)$r['cnt'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</div>
</body>
</html>
