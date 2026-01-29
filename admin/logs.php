<?php
// admin/logs.php
declare(strict_types=1);

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/auth.php";
require_login();

$door_id = trim((string)($_GET['door_id'] ?? ''));
$card_uid = strtoupper(trim((string)($_GET['card_uid'] ?? '')));
$result = trim((string)($_GET['result'] ?? ''));
$from = trim((string)($_GET['from'] ?? '')); // yyyy-mm-dd
$to = trim((string)($_GET['to'] ?? ''));

$sql = "SELECT l.ts_server, l.door_id, l.card_uid, l.result, l.reason, l.employee_id, l.ip_addr,
               e.first_name, e.last_name
        FROM access_logs l
        LEFT JOIN employees e ON e.employee_id = l.employee_id
        WHERE 1=1 ";
$params = [];

if ($door_id !== '') { $sql .= " AND l.door_id = ? "; $params[] = $door_id; }
if ($card_uid !== '') { $sql .= " AND l.card_uid = ? "; $params[] = $card_uid; }
if ($result !== '' && in_array($result, ['ALLOW','DENY'], true)) { $sql .= " AND l.result = ? "; $params[] = $result; }
if ($from !== '') { $sql .= " AND DATE(l.ts_server) >= ? "; $params[] = $from; }
if ($to !== '') { $sql .= " AND DATE(l.ts_server) <= ? "; $params[] = $to; }

$sql .= " ORDER BY l.ts_server DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// dropdown doors
$doors = $pdo->query("SELECT door_id, door_name FROM doors ORDER BY door_name")->fetchAll();
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Access Logs</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <span class="navbar-brand">Access Logs</span>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-light btn-sm" href="dashboard.php">Dashboard</a>
      <a class="btn btn-outline-light btn-sm" href="logout.php">Logout</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2" method="get">
        <div class="col-12 col-lg-3">
          <label class="form-label">Door</label>
          <select class="form-select" name="door_id">
            <option value="">ทั้งหมด</option>
            <?php foreach ($doors as $d): ?>
              <option value="<?= htmlspecialchars($d['door_id']) ?>" <?= $door_id===$d['door_id']?'selected':'' ?>>
                <?= htmlspecialchars($d['door_name']) ?> (<?= htmlspecialchars($d['door_id']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-lg-2">
          <label class="form-label">Card UID</label>
          <input class="form-control" name="card_uid" value="<?= htmlspecialchars($card_uid) ?>">
        </div>
        <div class="col-12 col-lg-2">
          <label class="form-label">Result</label>
          <select class="form-select" name="result">
            <option value="">ทั้งหมด</option>
            <option value="ALLOW" <?= $result==='ALLOW'?'selected':'' ?>>ALLOW</option>
            <option value="DENY" <?= $result==='DENY'?'selected':'' ?>>DENY</option>
          </select>
        </div>
        <div class="col-6 col-lg-2">
          <label class="form-label">จากวันที่</label>
          <input class="form-control" type="date" name="from" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div class="col-6 col-lg-2">
          <label class="form-label">ถึงวันที่</label>
          <input class="form-control" type="date" name="to" value="<?= htmlspecialchars($to) ?>">
        </div>
        <div class="col-12 col-lg-1 d-grid">
          <button class="btn btn-primary mt-4" type="submit">ค้นหา</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>เวลา</th>
              <th>Door</th>
              <th>Card UID</th>
              <th>พนักงาน</th>
              <th>ผล</th>
              <th>เหตุผล</th>
              <th>IP</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="text-muted"><?= htmlspecialchars((string)$r['ts_server']) ?></td>
                <td><?= htmlspecialchars($r['door_id']) ?></td>
                <td class="fw-semibold"><?= htmlspecialchars($r['card_uid']) ?></td>
                <td><?= htmlspecialchars(trim(($r['first_name']??'')." ".($r['last_name']??'')) ?: '-') ?></td>
                <td><?= $r['result']==='ALLOW' ? '<span class="badge text-bg-success">ALLOW</span>' : '<span class="badge text-bg-danger">DENY</span>' ?></td>
                <td><?= htmlspecialchars($r['reason']) ?></td>
                <td class="text-muted"><?= htmlspecialchars((string)$r['ip_addr']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="small text-muted mt-2">* แสดงล่าสุด 500 รายการ</div>
    </div>
  </div>
</div>
</body>
</html>
