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

function json_error($message = 'error', $code = 1, $data = null, $httpCode = 400) {
    if ($httpCode >= 400) {
        http_response_code($httpCode);
    }
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
    $userId = $config['user']['default_user_id'] ?? null;
    if (!$userId) {
        json_error('未登录或登录已过期', 401, null, 401);
    }
    return intval($userId);
}

function dispatch_exception(Exception $e) {
    $msg = $e->getMessage();
    $code = $e->getCode() ?: 500;
    $context = null;
    $httpCode = 500;

    if (method_exists($e, 'getContext')) {
        $context = $e->getContext();
    }

    $exceptionClass = get_class($e);

    switch ($exceptionClass) {
        case 'InvalidArgumentException':
        case 'InvalidOrderArgumentException':
            $httpCode = 400;
            break;
        case 'InsufficientBalanceException':
            $httpCode = 402;
            if ($context && empty($context)) {
                $context = null;
            }
            break;
        case 'OrderNotFoundException':
            $httpCode = 404;
            break;
        case 'OrderPermissionDeniedException':
            $httpCode = 403;
            break;
        case 'OrderStateException':
            $httpCode = 409;
            break;
        case 'RuntimeException':
            $httpCode = 500;
            $code = $code ?: 500;
            break;
        default:
            $httpCode = 500;
            $code = 500;
            $msg = '服务器内部错误';
            break;
    }

    json_error($msg, $code, $context, $httpCode);
}

set_exception_handler('dispatch_exception');
