<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
http_response_code(501);
echo json_encode([
    'error' => 'Mode PHP agent belum diprioritaskan pada rilis awal. Gunakan agents/agent.sh (push mode).',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
