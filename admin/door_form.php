<?php
// admin/door_form.php
declare(strict_types=1);

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/security.php";
require_once __DIR__ . "/auth.php";
require_login();

function generate_token(int $bytes = 32): string {
  return bin2hex(random_bytes($bytes)); // 64 chars
}

$door_id = (string)($_GET['door_id'] ?? '');
$isEdit = $door_id !== '';
$plain_token = null;
$flash = null;

$door = [
  'door_id' => '',
  'door_name' => '',
  'location_path' => '',
  'is_default' => 0,
  'status' => 'active'
];

if ($isEdit) {
  $stmt = $pdo->prepare("SELECT door_id, door_name, location_path, is_default, status FROM doors WHERE door_id=?");
  $stmt->execute([$door_id]);
  $row = $stmt->fetch();
  if (!$row) { http_response_code(404); echo "Door not found"; exit; }
  $door = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? 'save');

  $door_id_in = trim((string)($_POST['door_id'] ?? ''));
  $door_name = trim((string)($_POST['door_name'] ?? ''));
  $location_path = trim((string)($_POST['location_path'] ?? ''));
  $is_default = isset($_POST['is_default']) ? 1 : 0;
  $status = (string)($_POST['status'] ?? 'active');

  if ($action === 'rotate_token') {
    // rotate token ต้องเป็น edit เท่านั้น
    $door_id_in = trim((string)($_POST['door_id'] ?? ''));
    if ($door_id_in === '') { $flash = ["type"=>"danger","msg"=>"door_id ไม่ถูกต้อง"]; }
    else {
      $plain_token = generate_token(32);
      $hash = hash_door_token($plain_token);
      $pdo->prepare("UPDATE doors SET doors_token_hash=?, last_seen_at=last_seen_at WHERE door_id=?")->execute([$hash, $door_id_in]);
      $flash = ["type"=>"success","msg"=>"หมุน Token สำเร็จ (คัดลอก Token ไปใส่ ESP)"];
    }
  } else {
    if ($door_id_in === '' || $door_name === '') {
      $flash = ["type"=>"danger","msg"=>"กรุณากรอก Door ID และชื่อประตู"];
    } else {
      if ($isEdit) {
        $stmt = $pdo->prepare("UPDATE doors SET door_name=?, location_path=?, is_default=?, status=? WHERE door_id=?");
        $stmt->execute([$door_name, $location_path, $is_default, $status, $door_id_in]);
        $flash = ["type"=>"success","msg"=>"บันทึกข้อมูลประตูเรียบร้อย"];
      } else {
        // create ใหม่: สร้าง token
        $plain_token = generate_token(32);
        $hash = hash_door_token($plain_token);
        $stmt = $pdo->prepare("INSERT INTO doors (door_id, door_name, location_path, doors_token_hash, is_default, status)
                               VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$door_id_in, $door_name, $location_path, $hash, $is_default, $status]);
        $flash = ["type"=>"success","msg"=>"เพิ่มประตูเรียบร้อย (คัดลอก Token ไปใส่ ESP)"];
        $isEdit = true;
      }

      // reload
      $stmt = $pdo->prepare("SELECT door_id, door_name, location_path, is_default, status FROM doors WHERE door_id=?");
      $stmt->execute([$door_id_in]);
      $door = $stmt->fetch();
      $door_id = $door['door_id'];
    }
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $isEdit ? "แก้ไขประตู" : "เพิ่มประตู" ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <span class="navbar-brand"><?= $isEdit ? "แก้ไขประตู" : "เพิ่มประตู" ?></span>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-light btn-sm" href="doors.php">กลับรายการ</a>
      <a class="btn btn-outline-light btn-sm" href="dashboard.php">Dashboard</a>
    </div>
  </div>
</nav>

<div class="container py-4" style="max-width: 900px;">
  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <?php if ($plain_token): ?>
    <div class="alert alert-warning">
      <div class="fw-bold mb-1">Door Token (แสดงครั้งนี้ครั้งเดียว)</div>
      <div class="d-flex gap-2 align-items-center">
        <input class="form-control" id="tok" type="password" value="<?= htmlspecialchars($plain_token) ?>" readonly>
        <button class="btn btn-outline-secondary" type="button" id="tokToggle">&#x0E41;&#x0E2A;&#x0E14;&#x0E07;</button>
        <button class="btn btn-dark" type="button" id="tokCopy">&#x0E04;&#x0E31;&#x0E14;&#x0E25;&#x0E2D;&#x0E01;</button>
      </div>
      <div class="small text-muted mt-2">นำ token นี้ไปใส่ใน ESP8266 ของประตูนี้</div>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="action" value="save">

        <div class="col-12 col-lg-4">
          <label class="form-label">Door ID</label>
          <input class="form-control" name="door_id" value="<?= htmlspecialchars($door['door_id']) ?>" <?= $isEdit ? "readonly" : "" ?> required>
        </div>

        <div class="col-12 col-lg-8">
          <label class="form-label">ชื่อประตู</label>
          <input class="form-control" name="door_name" value="<?= htmlspecialchars($door['door_name']) ?>" required>
        </div>

        <div class="col-12">
          <label class="form-label">Location Path</label>
          <input class="form-control" name="location_path" value="<?= htmlspecialchars((string)$door['location_path']) ?>" placeholder="Building A / Floor 2 / Zone East">
        </div>

        <div class="col-12 col-lg-4">
          <label class="form-label">สถานะ</label>
          <select class="form-select" name="status">
            <option value="active" <?= $door['status']==='active'?'selected':'' ?>>active</option>
            <option value="disabled" <?= $door['status']==='disabled'?'selected':'' ?>>disabled</option>
          </select>
        </div>

        <div class="col-12 col-lg-4 d-flex align-items-center">
          <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="is_default" id="is_default" <?= (int)$door['is_default']===1?'checked':'' ?>>
            <label class="form-check-label" for="is_default">เป็น Default Door (ติ๊กให้เริ่มต้น)</label>
          </div>
        </div>

        <div class="col-12 d-grid d-lg-flex gap-2 justify-content-end">
          <button class="btn btn-primary px-4" type="submit">บันทึก</button>
          <?php if ($isEdit): ?>
            <button class="btn btn-outline-danger" type="submit"
                    name="action" value="rotate_token"
                    onclick="return confirm('หมุน Token ใหม่? ต้องนำ token ใหม่ไปใส่ ESP ด้วย')">
              Rotate Token
            </button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($plain_token): ?>
<script>
(() => {
  const tok = document.getElementById("tok");
  const btnToggle = document.getElementById("tokToggle");
  const btnCopy = document.getElementById("tokCopy");
  if (!tok || !btnToggle || !btnCopy) return;

  btnToggle.addEventListener("click", () => {
    const isHidden = tok.type === "password";
    tok.type = isHidden ? "text" : "password";
    btnToggle.textContent = isHidden ? "\u0E0B\u0E48\u0E2D\u0E19" : "\u0E41\u0E2A\u0E14\u0E07";
  });

  btnCopy.addEventListener("click", async () => {
    try {
      await navigator.clipboard.writeText(tok.value);
      btnCopy.textContent = "\u0E04\u0E31\u0E14\u0E25\u0E2D\u0E01\u0E41\u0E25\u0E49\u0E27";
      setTimeout(() => { btnCopy.textContent = "\u0E04\u0E31\u0E14\u0E25\u0E2D\u0E01"; }, 1200);
    } catch (e) {
      alert("\u0E04\u0E31\u0E14\u0E25\u0E2D\u0E01\u0E44\u0E21\u0E48\u0E2A\u0E33\u0E40\u0E23\u0E47\u0E08");
    }
  });
})();
</script>
<?php endif; ?>
</body>
</html>
