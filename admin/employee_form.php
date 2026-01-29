<?php
// admin/employee_form.php
declare(strict_types=1);

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/auth.php";
require_login();

$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$isEdit = $employee_id > 0;

$flash = null;

$emp = [
  'employee_id' => 0,
  'emp_code' => '',
  'first_name' => '',
  'last_name' => '',
  'department' => '',
  'position' => '',
  'status' => 'active'
];

if ($isEdit) {
  $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id=?");
  $stmt->execute([$employee_id]);
  $row = $stmt->fetch();
  if (!$row) { http_response_code(404); echo "Employee not found"; exit; }
  $emp = $row;
}

// actions: save employee / add card / update card status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? 'save');

  if ($action === 'save') {
    $emp_code = trim((string)($_POST['emp_code'] ?? ''));
    $first_name = trim((string)($_POST['first_name'] ?? ''));
    $last_name = trim((string)($_POST['last_name'] ?? ''));
    $department = trim((string)($_POST['department'] ?? ''));
    $position = trim((string)($_POST['position'] ?? ''));
    $status = (string)($_POST['status'] ?? 'active');

    if ($first_name==='' || $last_name==='') {
      $flash = ["type"=>"danger","msg"=>"กรุณากรอกชื่อ-นามสกุล"];
    } else {
      if ($isEdit) {
        $stmt = $pdo->prepare("UPDATE employees SET emp_code=?, first_name=?, last_name=?, department=?, position=?, status=? WHERE employee_id=?");
        $stmt->execute([$emp_code,$first_name,$last_name,$department,$position,$status,$employee_id]);
        $flash = ["type"=>"success","msg"=>"บันทึกข้อมูลพนักงานเรียบร้อย"];
      } else {
        $stmt = $pdo->prepare("INSERT INTO employees (emp_code, first_name, last_name, department, position, status) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$emp_code,$first_name,$last_name,$department,$position,$status]);
        $employee_id = (int)$pdo->lastInsertId();
        $isEdit = true;
        $flash = ["type"=>"success","msg"=>"เพิ่มพนักงานเรียบร้อย"];
      }

      // reload
      $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id=?");
      $stmt->execute([$employee_id]);
      $emp = $stmt->fetch();
    }
  }

  if ($action === 'add_card') {
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $card_uid = strtoupper(trim((string)($_POST['card_uid'] ?? '')));
    $note = trim((string)($_POST['note'] ?? ''));

    if ($employee_id<=0 || $card_uid==='') {
      $flash = ["type"=>"danger","msg"=>"กรุณากรอก Card UID"];
    } else {
      try {
        $stmt = $pdo->prepare("INSERT INTO nfc_cards (card_uid, employee_id, status, note) VALUES (?,?, 'active', ?)");
        $stmt->execute([$card_uid, $employee_id, $note]);
        $flash = ["type"=>"success","msg"=>"เพิ่มบัตรเรียบร้อย (Active)"];
      } catch (Throwable $e) {
        $flash = ["type"=>"danger","msg"=>"เพิ่มบัตรไม่สำเร็จ: UID ซ้ำหรือข้อมูลผิด"];
      }
    }
  }

  if ($action === 'update_card_status') {
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $card_uid = strtoupper(trim((string)($_POST['card_uid'] ?? '')));
    $card_status = (string)($_POST['card_status'] ?? 'active');

    if ($employee_id>0 && $card_uid!=='') {
      $stmt = $pdo->prepare("UPDATE nfc_cards SET status=? , revoked_at=IF(?='active', NULL, NOW()) WHERE card_uid=? AND employee_id=?");
      $stmt->execute([$card_status, $card_status, $card_uid, $employee_id]);
      $flash = ["type"=>"success","msg"=>"อัปเดตสถานะบัตรเรียบร้อย"];
    }
  }
}

// cards list
$cards = [];
if ($isEdit) {
  $stmt = $pdo->prepare("SELECT card_uid, status, issued_at, revoked_at, note FROM nfc_cards WHERE employee_id=? ORDER BY issued_at DESC");
  $stmt->execute([$employee_id]);
  $cards = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $isEdit ? "แก้ไขพนักงาน" : "เพิ่มพนักงาน" ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <span class="navbar-brand"><?= $isEdit ? "แก้ไขพนักงาน" : "เพิ่มพนักงาน" ?></span>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-light btn-sm" href="employees.php">กลับรายการ</a>
      <a class="btn btn-outline-light btn-sm" href="dashboard.php">Dashboard</a>
    </div>
  </div>
</nav>

<div class="container py-4" style="max-width: 980px;">
  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="action" value="save">
        <div class="col-12 col-lg-3">
          <label class="form-label">รหัสพนักงาน</label>
          <input class="form-control" name="emp_code" value="<?= htmlspecialchars((string)$emp['emp_code']) ?>">
        </div>
        <div class="col-12 col-lg-3">
          <label class="form-label">ชื่อ</label>
          <input class="form-control" name="first_name" value="<?= htmlspecialchars($emp['first_name']) ?>" required>
        </div>
        <div class="col-12 col-lg-3">
          <label class="form-label">นามสกุล</label>
          <input class="form-control" name="last_name" value="<?= htmlspecialchars($emp['last_name']) ?>" required>
        </div>
        <div class="col-12 col-lg-3">
          <label class="form-label">สถานะ</label>
          <select class="form-select" name="status">
            <?php foreach (['active','inactive','suspended','resigned'] as $st): ?>
              <option value="<?= $st ?>" <?= $emp['status']===$st?'selected':'' ?>><?= $st ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-lg-6">
          <label class="form-label">แผนก</label>
          <input class="form-control" name="department" value="<?= htmlspecialchars((string)$emp['department']) ?>">
        </div>
        <div class="col-12 col-lg-6">
          <label class="form-label">ตำแหน่ง</label>
          <input class="form-control" name="position" value="<?= htmlspecialchars((string)$emp['position']) ?>">
        </div>

        <div class="col-12 d-grid d-lg-flex gap-2 justify-content-end">
          <button class="btn btn-primary px-4" type="submit">บันทึก</button>
          <?php if ($isEdit): ?>
            <a class="btn btn-warning" href="permissions.php?employee_id=<?= (int)$employee_id ?>">กำหนดสิทธิ์ (ACL)</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <?php if ($isEdit): ?>
  <div class="card shadow-sm">
    <div class="card-header bg-white"><strong>บัตร NFC ของพนักงาน</strong></div>
    <div class="card-body">
      <form method="post" class="row g-2 mb-3">
        <input type="hidden" name="action" value="add_card">
        <input type="hidden" name="employee_id" value="<?= (int)$employee_id ?>">
        <div class="col-12 col-lg-4">
          <label class="form-label">Card UID</label>
          <input class="form-control" name="card_uid" placeholder="เช่น 04A1B2C3D4" required>
        </div>
        <div class="col-12 col-lg-6">
          <label class="form-label">หมายเหตุ</label>
          <input class="form-control" name="note" placeholder="เช่น บัตรใหม่ / สำรอง">
        </div>
        <div class="col-12 col-lg-2 d-grid">
          <button class="btn btn-outline-primary mt-4" type="submit">เพิ่มบัตร</button>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>Card UID</th>
              <th>Status</th>
              <th>Issued</th>
              <th>Revoked</th>
              <th>หมายเหตุ</th>
              <th class="text-end">อัปเดต</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cards as $c): ?>
              <tr>
                <td class="fw-semibold"><?= htmlspecialchars(strtoupper($c['card_uid'])) ?></td>
                <td><?= htmlspecialchars($c['status']) ?></td>
                <td class="text-muted"><?= htmlspecialchars((string)$c['issued_at']) ?></td>
                <td class="text-muted"><?= htmlspecialchars((string)$c['revoked_at']) ?></td>
                <td><?= htmlspecialchars((string)$c['note']) ?></td>
                <td class="text-end">
                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="update_card_status">
                    <input type="hidden" name="employee_id" value="<?= (int)$employee_id ?>">
                    <input type="hidden" name="card_uid" value="<?= htmlspecialchars(strtoupper($c['card_uid'])) ?>">
                    <select class="form-select form-select-sm d-inline-block" name="card_status" style="width:160px">
                      <?php foreach (['active','blocked','lost','expired'] as $st): ?>
                        <option value="<?= $st ?>" <?= $c['status']===$st?'selected':'' ?>><?= $st ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-outline-secondary" type="submit">บันทึก</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$cards): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีบัตร</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    </div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
