<?php
// Copy this file to config.php and enter your database settings.
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_USER = getenv('DB_USER') ?: 'your_database_user';
$DB_PASS = getenv('DB_PASS') ?: 'your_database_password';
$DB_NAME = getenv('DB_NAME') ?: 'worksync';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (Exception $e) {
    error_log($e->getMessage());
    die("Error: ทางระบบขัดข้องเรื่องการติดต่อฐานข้อมูลชั่วคราว โปรดติดต่อผู้ดูแลระบบ");
}
