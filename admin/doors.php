<?php
// admin/doors.php
declare(strict_types=1);

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/auth.php";
require_login();
$admin = current_admin();

$q = trim((string)($_GET['q'] ?? ''));

$sql = "SELECT door_id, door_name, location_path, is_default, status, last_seen_at
        FROM doors WHERE 1=1 ";
$params = [];

if ($q !== '') {
  $sql .= " AND (door_id LIKE ? OR door_name LIKE ? OR location_path LIKE ?) ";
  $like = "%$q%";
  $params = [$like, $like, $like];
}
$sql .= " ORDER BY location_path, door_name, door_id";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$doors = $stmt->fetchAll();

// toggle status
if (isset($_GET['toggle']) && $_GET['toggle'] !== '') {
  $door_id = (string)$_GET['toggle'];
  $pdo->prepare("UPDATE doors SET status=IF(status='active','disabled','active') WHERE door_id=?")->execute([$door_id]);
  header("Location: doors.php");
  exit;
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Doors</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <span class="navbar-brand">Doors</span>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-light btn-sm" href="dashboard.php">Dashboard</a>
      <a class="btn btn-warning btn-sm" href="permissions.php">Permissions</a>
      <a class="btn btn-outline-light btn-sm" href="logout.php">Logout</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">จัดการประตู (Doors)</h4>
    <a class="btn btn-primary" href="door_form.php">+ เพิ่มประตู</a>
  </div>

  <form class="row g-2 mb-3" method="get">
    <div class="col-12 col-lg-6">
      <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="ค้นหา door_id / ชื่อ / location">
    </div>
    <div class="col-6 col-lg-3">
      <button class="btn btn-outline-primary w-100">ค้นหา</button>
    </div>
    <div class="col-6 col-lg-3">
      <a class="btn btn-outline-secondary w-100" href="doors.php">ล้าง</a>
    </div>
  </form>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>Door ID</th>
              <th>ชื่อ</th>
              <th>Location</th>
              <th>Default</th>
              <th>Status</th>
              <th>Last Seen</th>
              <th class="text-end">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($doors as $d): ?>
              <tr>
                <td class="fw-semibold"><?= htmlspecialchars($d['door_id']) ?></td>
                <td><?= htmlspecialchars($d['door_name']) ?></td>
                <td class="text-muted"><?= htmlspecialchars((string)$d['location_path']) ?></td>
                <td><?= (int)$d['is_default']===1 ? '<span class="badge text-bg-info">Default</span>' : '—' ?></td>
                <td><?= $d['status']==='active' ? '<span class="badge text-bg-success">active</span>' : '<span class="badge text-bg-secondary">disabled</span>' ?></td>
                <td class="text-muted"><?= htmlspecialchars((string)$d['last_seen_at']) ?></td>
                <td class="text-end">
                  <a class="btn btn-outline-secondary btn-sm" href="door_form.php?door_id=<?= urlencode($d['door_id']) ?>">แก้ไข</a>
                  <a class="btn btn-outline-warning btn-sm" href="doors.php?toggle=<?= urlencode($d['door_id']) ?>"
                     onclick="return confirm('สลับสถานะประตูนี้?')">สลับสถานะ</a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$doors): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body>
</html>
