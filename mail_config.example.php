<?php
/**
 * mail_config.example.php — เทมเพลตสำหรับตั้งค่า SMTP
 * คัดลอกไฟล์นี้เป็น mail_config.php แล้วใส่ค่าจริง (ไฟล์นั้นจะไม่ถูก commit)
 */

define('MAIL_HOST', getenv('MAIL_HOST') ?: 'smtp.gmail.com');
define('MAIL_PORT', (int)(getenv('MAIL_PORT') ?: 587));
define('MAIL_USER', getenv('MAIL_USER') ?: 'your-email@gmail.com');
define('MAIL_PASS', getenv('MAIL_PASS') ?: 'your-gmail-app-password');
define('MAIL_FROM', getenv('MAIL_FROM') ?: (getenv('MAIL_USER') ?: 'your-email@gmail.com'));
define('MAIL_NAME', getenv('MAIL_NAME') ?: 'WorkSync');
define('SITE_URL', rtrim(getenv('SITE_URL') ?: 'http://localhost', '/'));
