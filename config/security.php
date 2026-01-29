<?php
// config/security.php
declare(strict_types=1);

function hash_door_token(string $token): string {
  return password_hash($token, PASSWORD_BCRYPT);
}

function verify_door_token(string $token, string $hash): bool {
  return password_verify($token, $hash);
}

function json_response(array $data, int $code = 200): void {
  http_response_code($code);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}
