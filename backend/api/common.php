<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function json_response($data = null, $message = 'success', $code = 0) {
    echo json_encode([
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error($message = 'error', $code = 1, $data = null) {
    echo json_encode([
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_request_data() {
    $input = file_get_contents('php://input');
    if (!$input) {
        return $_REQUEST;
    }
    $data = json_decode($input, true);
    if (!is_array($data)) {
        return $_REQUEST;
    }
    return array_merge($_REQUEST, $data);
}

function get_current_user_id() {
    $config = require __DIR__ . '/../config/config.php';
    return $config['user']['default_user_id'];
}
