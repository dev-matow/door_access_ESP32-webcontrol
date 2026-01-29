<?php
// api/heartbeat.php
declare(strict_types=1);

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/security.php";

$raw = file_get_contents("php://input");
$body = json_decode($raw, true);

if (!is_array($body)) json_response(["ok"=>false,"reason"=>"INVALID_JSON"], 400);

$door_id = trim((string)($body["door_id"] ?? ""));
$ts = (int)($body["ts"] ?? 0);
$doors_token = (string)($body["doors_token"] ?? "");

if ($door_id==="" || $ts<=0 || $doors_token==="") {
  json_response(["ok"=>false,"reason"=>"INVALID_INPUT"], 400);
}

$stmt = $pdo->prepare("SELECT doors_token_hash, status FROM doors WHERE door_id=? LIMIT 1");
$stmt->execute([$door_id]);
$door = $stmt->fetch();

if (!$door) json_response(["ok"=>false,"reason"=>"DOOR_NOT_FOUND"], 404);
if ($door["status"]!=="active") json_response(["ok"=>false,"reason"=>"DOOR_DISABLED"], 403);
if (!verify_door_token($doors_token, $door["doors_token_hash"])) json_response(["ok"=>false,"reason"=>"INVALID_DOOR_TOKEN"], 401);

$pdo->prepare("UPDATE doors SET last_seen_at=NOW() WHERE door_id=?")->execute([$door_id]);

json_response(["ok"=>true], 200);
