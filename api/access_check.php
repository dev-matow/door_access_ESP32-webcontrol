<?php
// api/access_check.php
declare(strict_types=1);

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/security.php";

// ---------- read JSON ----------
$raw = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) {
  json_response(["allowed" => false, "reason" => "INVALID_JSON"], 400);
}

// ---------- inputs ----------
$door_id = trim((string)($body["door_id"] ?? ""));
$card_uid = strtoupper(trim((string)($body["card_uid"] ?? "")));
$ts = (int)($body["ts"] ?? 0);
$doors_token = (string)($body["doors_token"] ?? "");

if ($door_id === "" || $card_uid === "" || $ts <= 0 || $doors_token === "") {
  json_response(["allowed" => false, "reason" => "INVALID_INPUT"], 400);
}

// ---------- anti-replay (basic) ----------
$now = time();
if (abs($now - $ts) > 60) {
  $pdo->prepare("
    INSERT INTO access_logs (ts_client, door_id, card_uid, result, reason, ip_addr)
    VALUES (?, ?, ?, 'DENY', 'TS_EXPIRED', ?)
  ")->execute([$ts, $door_id, $card_uid, $_SERVER["REMOTE_ADDR"] ?? null]);

  json_response(["allowed" => false, "reason" => "TS_EXPIRED"], 401);
}

try {
  // 1) verify door + token
  $stmt = $pdo->prepare("SELECT status, doors_token_hash FROM doors WHERE door_id=? LIMIT 1");
  $stmt->execute([$door_id]);
  $door = $stmt->fetch();

  if (!$door) {
    $pdo->prepare("
      INSERT INTO access_logs (ts_client, door_id, card_uid, result, reason, ip_addr)
      VALUES (?, ?, ?, 'DENY', 'DOOR_NOT_FOUND', ?)
    ")->execute([$ts, $door_id, $card_uid, $_SERVER["REMOTE_ADDR"] ?? null]);

    json_response(["allowed" => false, "reason" => "DOOR_NOT_FOUND"], 404);
  }

  if ($door["status"] !== "active") {
    $pdo->prepare("
      INSERT INTO access_logs (ts_client, door_id, card_uid, result, reason, ip_addr)
      VALUES (?, ?, ?, 'DENY', 'DOOR_DISABLED', ?)
    ")->execute([$ts, $door_id, $card_uid, $_SERVER["REMOTE_ADDR"] ?? null]);

    json_response(["allowed" => false, "reason" => "DOOR_DISABLED"], 403);
  }

  if (!verify_door_token($doors_token, (string)$door["doors_token_hash"])) {
    $pdo->prepare("
      INSERT INTO access_logs (ts_client, door_id, card_uid, result, reason, ip_addr)
      VALUES (?, ?, ?, 'DENY', 'INVALID_DOOR_TOKEN', ?)
    ")->execute([$ts, $door_id, $card_uid, $_SERVER["REMOTE_ADDR"] ?? null]);

    json_response(["allowed" => false, "reason" => "INVALID_DOOR_TOKEN"], 401);
  }

  // update last_seen
  $pdo->prepare("UPDATE doors SET last_seen_at=NOW() WHERE door_id=?")->execute([$door_id]);

  // 2) verify card
  $stmt = $pdo->prepare("SELECT employee_id, status FROM nfc_cards WHERE card_uid=? LIMIT 1");
  $stmt->execute([$card_uid]);
  $card = $stmt->fetch();

  if (!$card) {
    $pdo->prepare("
      INSERT INTO access_logs (ts_client, door_id, card_uid, result, reason, ip_addr)
      VALUES (?, ?, ?, 'DENY', 'CARD_NOT_FOUND', ?)
    ")->execute([$ts, $door_id, $card_uid, $_SERVER["REMOTE_ADDR"] ?? null]);

    json_response(["allowed" => false, "reason" => "CARD_NOT_FOUND"], 404);
  }

  if ($card["status"] !== "active") {
    $pdo->prepare("
      INSERT INTO access_logs (ts_client, door_id, card_uid, result, reason, employee_id, ip_addr)
      VALUES (?, ?, ?, 'DENY', 'CARD_BLOCKED', ?, ?)
    ")->execute([$ts, $door_id, $card_uid, (int)$card["employee_id"], $_SERVER["REMOTE_ADDR"] ?? null]);

    json_response(["allowed" => false, "reason" => "CARD_BLOCKED"], 403);
  }

  $employee_id = (int)$card["employee_id"];

  // 3) check ACL
  $stmt = $pdo->prepare("SELECT allow FROM acl_permissions WHERE card_uid=? AND door_id=? LIMIT 1");
  $stmt->execute([$card_uid, $door_id]);
  $perm = $stmt->fetch();

  $allowed = ($perm && (int)$perm["allow"] === 1);

  // 4) log result
  $pdo->prepare("
    INSERT INTO access_logs (ts_client, door_id, card_uid, result, reason, employee_id, ip_addr)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  ")->execute([
    $ts,
    $door_id,
    $card_uid,
    $allowed ? "ALLOW" : "DENY",
    $allowed ? "OK" : "NO_PERMISSION",
    $employee_id,
    $_SERVER["REMOTE_ADDR"] ?? null
  ]);

  // 5) response (ไม่มี open_ms)
  json_response([
    "allowed" => $allowed,
    "reason"  => $allowed ? "OK" : "NO_PERMISSION"
  ], 200);

} catch (Throwable $e) {
  json_response(["allowed" => false, "reason" => "ERROR"], 500);
}
