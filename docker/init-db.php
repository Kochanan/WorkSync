<?php
mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('DB_HOST') ?: '';
$user = getenv('DB_USER') ?: '';
$password = getenv('DB_PASS') ?: '';

if ($host === '' || $user === '') {
    fwrite(STDERR, "DB_HOST and DB_USER must be set before the app starts.\n");
    exit(1);
}

$db = null;
$lastError = 'Database is not reachable';
for ($attempt = 1; $attempt <= 60; $attempt++) {
    $candidate = @new mysqli($host, $user, $password);
    if ($candidate->connect_errno === 0) {
        $db = $candidate;
        break;
    }
    $lastError = $candidate->connect_error ?: $lastError;
    sleep(2);
}

if (!$db instanceof mysqli) {
    fwrite(STDERR, "Could not connect to MySQL: {$lastError}\n");
    exit(1);
}

$schemaPath = '/var/www/html/worksync_full.sql';
$schema = file_get_contents($schemaPath);
if ($schema === false) {
    fwrite(STDERR, "Could not read the WorkSync schema.\n");
    exit(1);
}

if (!$db->multi_query($schema)) {
    fwrite(STDERR, "Could not initialize the WorkSync schema: {$db->error}\n");
    exit(1);
}

do {
    $result = $db->store_result();
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    if ($db->more_results() && !$db->next_result()) {
        fwrite(STDERR, "Could not finish the WorkSync schema: {$db->error}\n");
        exit(1);
    }
} while ($db->more_results());

$db->close();
