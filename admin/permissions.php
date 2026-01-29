<?php
// admin/permissions.php
declare(strict_types=1);

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/auth.php";
require_login();

// -------------------------------
// helpers
// -------------------------------
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// -------------------------------
// รับค่าเลือกพนักงาน + บัตร
// -------------------------------
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$card_uid_selected = strtoupper(trim((string)($_GET['card_uid'] ?? "")));

$door_q = trim((string)($_GET['q'] ?? ""));          // search door
$loc_q  = trim((string)($_GET['loc'] ?? ""));        // filter location_path

$flash = null;

// -------------------------------
// บันทึกสิทธิ์ (POST)
// -------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $employee_id = (int)($_POST['employee_id'] ?? 0);
  $card_uid_selected = strtoupper(trim((string)($_POST['card_uid'] ?? "")));
  $selected_doors = $_POST['door_ids'] ?? [];

  if ($employee_id <= 0 || $card_uid_selected === "") {
    $flash = ["type" => "danger", "msg" => "ข้อมูลไม่ครบ: กรุณาเลือกพนักงานและบัตร"];
  } else {
    // ตรวจว่าบัตรนี้เป็น active และผูกกับพนักงานจริง
    $stmt = $pdo->prepare("SELECT card_uid FROM nfc_cards WHERE card_uid=? AND employee_id=? AND status='active' LIMIT 1");
    $stmt->execute([$card_uid_selected, $employee_id]);
    $ok = $stmt->fetchColumn();

    if (!$ok) {
      $flash = ["type" => "danger", "msg" => "บัตรไม่ถูกต้อง หรือไม่อยู่ในสถานะ Active"];
    } else {
      // sanitize door ids (array of strings)
      $door_ids = [];
      if (is_array($selected_doors)) {
        foreach ($selected_doors as $d) {
          $d = trim((string)$d);
          if ($d !== "") $door_ids[] = $d;
        }
      }

      try {
        $pdo->beginTransaction();

        // ลบของเดิมก่อน (ง่าย/ชัวร์)
        $del = $pdo->prepare("DELETE FROM acl_permissions WHERE card_uid=?");
        $del->execute([$card_uid_selected]);

        // เพิ่มของใหม่แบบ bulk
        if (count($door_ids) > 0) {
          $ins = $pdo->prepare("INSERT INTO acl_permissions (card_uid, door_id, allow) VALUES (?, ?, 1)");
          foreach ($door_ids as $door_id) {
            $ins->execute([$card_uid_selected, $door_id]);
          }
        }

        $pdo->commit();
        $flash = ["type" => "success", "msg" => "บันทึกสิทธิ์เรียบร้อยแล้ว"];
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ["type" => "danger", "msg" => "เกิดข้อผิดพลาดในการบันทึก"];
      }
    }
  }
}

// -------------------------------
// โหลดข้อมูลพนักงานสำหรับ dropdown
// -------------------------------
$employees = $pdo->query("
  SELECT employee_id, emp_code, first_name, last_name, department, status
  FROM employees
  WHERE status IN ('active','inactive','suspended')
  ORDER BY first_name, last_name
")->fetchAll();

// -------------------------------
// ถ้าเลือก employee แล้ว โหลดบัตร active ทั้งหมดของคนนั้น
// -------------------------------
$cards = [];
if ($employee_id > 0) {
  $stmt = $pdo->prepare("SELECT card_uid, status FROM nfc_cards WHERE employee_id=? ORDER BY (status='active') DESC, issued_at DESC");
  $stmt->execute([$employee_id]);
  $cards = $stmt->fetchAll();

  // ถ้ายังไม่ได้ระบุ card_uid ให้เลือกใบที่ active ล่าสุดเป็นค่าเริ่มต้น
  if ($card_uid_selected === "") {
    foreach ($cards as $c) {
      if ($c['status'] === 'active') {
        $card_uid_selected = strtoupper($c['card_uid']);
        break;
      }
    }
  }
}

// -------------------------------
// โหลด doors (พร้อม search/filter) รองรับประตูเยอะ: ใช้ filter + index
// -------------------------------
$sqlDoors = "SELECT door_id, door_name, location_path, is_default, status
            FROM doors
            WHERE 1=1 ";
$params = [];

if ($door_q !== "") {
  $sqlDoors .= " AND (door_id LIKE ? OR door_name LIKE ? OR location_path LIKE ?) ";
  $like = "%" . $door_q . "%";
  $params[] = $like; $params[] = $like; $params[] = $like;
}

if ($loc_q !== "") {
  $sqlDoors .= " AND location_path LIKE ? ";
  $params[] = "%" . $loc_q . "%";
}

$sqlDoors .= " ORDER BY location_path, door_name, door_id ";

$stmt = $pdo->prepare($sqlDoors);
$stmt->execute($params);
$doors = $stmt->fetchAll();

// -------------------------------
// โหลด permission ที่มีอยู่สำหรับ card นี้
// -------------------------------
$existingPerm = [];      // door_id => true
$hasAnyPerm = false;

if ($card_uid_selected !== "") {
  $stmt = $pdo->prepare("SELECT door_id FROM acl_permissions WHERE card_uid=? AND allow=1");
  $stmt->execute([$card_uid_selected]);
  $rows = $stmt->fetchAll();
  if ($rows) {
    $hasAnyPerm = true;
    foreach ($rows as $r) $existingPerm[$r['door_id']] = true;
  }
}

// -------------------------------
// ถ้ายังไม่มีการตั้งสิทธิ์เลย ให้ติ๊ก default doors
// -------------------------------
$defaultPerm = [];
if ($card_uid_selected !== "" && !$hasAnyPerm) {
  foreach ($doors as $d) {
    if ((int)$d['is_default'] === 1 && $d['status'] === 'active') {
      $defaultPerm[$d['door_id']] = true;
    }
  }
}

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>กำหนดสิทธิ์เข้าออก (ACL)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <span class="navbar-brand">Door Access Admin</span>
    <div class="ms-auto">
      <a class="btn btn-outline-light btn-sm" href="dashboard.php">Dashboard</a>
      <a class="btn btn-outline-light btn-sm" href="employees.php">Employees</a>
      <a class="btn btn-outline-light btn-sm" href="doors.php">Doors</a>
      <a class="btn btn-warning btn-sm" href="permissions.php">Permissions</a>
    </div>
  </div>
</nav>

<div class="container py-4">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">กำหนดสิทธิ์เข้าออก (ACL: ติ๊กประตูให้คน)</h4>
    <span class="badge text-bg-secondary">API ใช้ (card_uid, door_id)</span>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?=h($flash['type'])?>"><?=h($flash['msg'])?></div>
  <?php endif; ?>

  <!-- เลือกพนักงาน/บัตร -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-12 col-lg-6">
          <label class="form-label">เลือกพนักงาน</label>
          <select class="form-select" name="employee_id" onchange="this.form.submit()">
            <option value="0">— เลือกพนักงาน —</option>
            <?php foreach ($employees as $e): ?>
              <?php
                $name = $e['first_name'] . " " . $e['last_name'];
                $meta = [];
                if (!empty($e['emp_code'])) $meta[] = $e['emp_code'];
                if (!empty($e['department'])) $meta[] = $e['department'];
                $label = $name . (count($meta) ? " (" . implode(" / ", $meta) . ")" : "");
              ?>
              <option value="<?= (int)$e['employee_id'] ?>" <?= $employee_id===(int)$e['employee_id'] ? "selected" : "" ?>>
                <?= h($label) ?> — <?= h($e['status']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-lg-3">
          <label class="form-label">บัตร (Card UID)</label>
          <select class="form-select" name="card_uid" <?= $employee_id>0 ? "" : "disabled" ?> onchange="this.form.submit()">
            <option value="">— เลือกบัตร —</option>
            <?php foreach ($cards as $c): ?>
              <option value="<?= h(strtoupper($c['card_uid'])) ?>" <?= strtoupper($c['card_uid'])===$card_uid_selected ? "selected" : "" ?>>
                <?= h(strtoupper($c['card_uid'])) ?> (<?= h($c['status']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-lg-3">
          <label class="form-label">ค้นหาประตู</label>
          <input class="form-control" name="q" value="<?=h($door_q)?>" placeholder="ค้นหา door_id / ชื่อประตู / location">
        </div>

        <div class="col-12 col-lg-3">
          <label class="form-label">กรอง location (คำค้น)</label>
          <input class="form-control" name="loc" value="<?=h($loc_q)?>" placeholder="เช่น Building A / Floor 2">
        </div>

        <div class="col-12 col-lg-3">
          <button class="btn btn-primary w-100" type="submit">ค้นหา/กรอง</button>
        </div>

        <div class="col-12 col-lg-3">
          <a class="btn btn-outline-secondary w-100" href="permissions.php">ล้างตัวกรอง</a>
        </div>

        <div class="col-12 col-lg-3">
          <div class="small text-muted">
            <?php if ($employee_id>0 && $card_uid_selected!==""): ?>
              <?php if ($hasAnyPerm): ?>
                โหลดสิทธิ์เดิมของบัตรนี้แล้ว
              <?php else: ?>
                ยังไม่มีสิทธิ์เดิม → ติ๊กค่าเริ่มต้น (Default Doors) ให้
              <?php endif; ?>
            <?php else: ?>
              กรุณาเลือกพนักงานและบัตรก่อน
            <?php endif; ?>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- รายการประตู + ติ๊กสิทธิ์ -->
  <div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
      <div>
        <strong>รายการประตู</strong>
        <span class="text-muted"> (<?= count($doors) ?> รายการ)</span>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="toggleAll(true)">ติ๊กทั้งหมด</button>
        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="toggleAll(false)">ยกเลิกทั้งหมด</button>
      </div>
    </div>

    <div class="card-body">
      <form method="post">
        <input type="hidden" name="employee_id" value="<?= (int)$employee_id ?>">
        <input type="hidden" name="card_uid" value="<?= h($card_uid_selected) ?>">

        <?php if ($employee_id<=0): ?>
          <div class="alert alert-warning mb-0">กรุณาเลือกพนักงานก่อน</div>
        <?php elseif ($card_uid_selected===""): ?>
          <div class="alert alert-warning mb-0">พนักงานคนนี้ยังไม่มีบัตร Active หรือยังไม่ได้เลือกบัตร</div>
        <?php else: ?>

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead class="table-light">
                <tr>
                  <th style="width: 70px;">เลือก</th>
                  <th style="width: 160px;">Door ID</th>
                  <th>ชื่อประตู</th>
                  <th>Location</th>
                  <th style="width: 120px;">Default</th>
                  <th style="width: 120px;">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($doors as $d): ?>
                  <?php
                    $door_id = $d['door_id'];
                    $is_active = ($d['status'] === 'active');

                    // กำหนดว่าติ๊กหรือไม่
                    $checked =
                      isset($existingPerm[$door_id]) ? true :
                      (isset($defaultPerm[$door_id]) ? true : false);

                    // ถ้าประตู disabled ให้ disable checkbox
                    $disabled_attr = $is_active ? "" : "disabled";
                  ?>
                  <tr>
                    <td>
                      <input class="form-check-input door-check"
                             type="checkbox"
                             name="door_ids[]"
                             value="<?= h($door_id) ?>"
                             <?= $checked ? "checked" : "" ?>
                             <?= $disabled_attr ?>>
                    </td>
                    <td class="fw-semibold"><?= h($door_id) ?></td>
                    <td><?= h($d['door_name']) ?></td>
                    <td class="text-muted"><?= h((string)$d['location_path']) ?></td>
                    <td>
                      <?php if ((int)$d['is_default'] === 1): ?>
                        <span class="badge text-bg-info">Default</span>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($is_active): ?>
                        <span class="badge text-bg-success">active</span>
                      <?php else: ?>
                        <span class="badge text-bg-secondary">disabled</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="d-grid d-lg-flex gap-2 justify-content-end">
            <button type="submit" class="btn btn-primary px-4">บันทึกสิทธิ์</button>
          </div>

        <?php endif; ?>
      </form>
    </div>
  </div>

</div>

<script>
function toggleAll(state) {
  document.querySelectorAll('.door-check').forEach(cb => {
    if (!cb.disabled) cb.checked = state;
  });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
