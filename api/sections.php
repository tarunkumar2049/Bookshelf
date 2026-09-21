<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed.', 405);
}

$db = Database::connection();

json_response([
    'ok' => true,
    'sections' => get_custom_sections($db, true),
]);
