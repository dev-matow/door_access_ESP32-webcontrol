<?php
// admin/create_admin.php  (รันครั้งเดียว แล้วลบทิ้ง)
declare(strict_types=1);
require_once __DIR__ . "/../config/db.php";

$username = "ponpan";         // เปลี่ยนได้
$password = "ponpan2542";    // เปลี่ยนทันทีหลังรัน
$role = "admin";

$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, role) VALUES (?, ?, ?)");
$stmt->execute([$username, $hash, $role]);

echo "Created admin: {$username}\n";
