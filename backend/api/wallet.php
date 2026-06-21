<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../services/WalletService.php';
require_once __DIR__ . '/../services/OrderService.php';
require_once __DIR__ . '/../models/User.php';

$walletService = new WalletService();
$orderService = new OrderService();
$userModel = new User();

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['action'] ?? '';

$userId = get_current_user_id();
$user = $userModel->findById($userId);

if (!$user) {
    json_error('用户信息不存在', 404, null, 404);
}

switch ($method) {
    case 'GET':
        if ($path === 'info') {
            $walletInfo = $walletService->getWalletInfo($userId);
            json_response([
                'user' => $user,
                'wallet' => $walletInfo,
            ]);
        } elseif ($path === 'transactions') {
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
            if ($limit <= 0 || $limit > 200) {
                $limit = 20;
            }
            $transactions = $walletService->getTransactions($userId, $limit);
            json_response($transactions);
        } elseif ($path === 'recharge-records') {
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
            if ($limit <= 0 || $limit > 200) {
                $limit = 20;
            }
            $records = $walletService->getRechargeRecords($userId, $limit);
            json_response($records);
        } elseif ($path === 'frozen-summary') {
            $summary = $orderService->getFrozenSummary($userId);
            json_response($summary);
        } else {
            json_error('无效的请求路径', 404, null, 404);
        }
        break;

    case 'POST':
        $data = get_request_data();

        if ($path === 'recharge') {
            $amount = isset($data['amount']) ? floatval($data['amount']) : 0;
            $channel = isset($data['channel']) ? trim(strval($data['channel'])) : 'manual';

            if ($amount <= 0) {
                json_error('充值金额必须大于0', 400, null, 400);
            }
            if ($amount > 9999999.99) {
                json_error('单次充值金额过大', 400, null, 400);
            }

            $allowedChannels = ['manual', 'wechat', 'alipay', 'bank_transfer'];
            if (!in_array($channel, $allowedChannels, true)) {
                $channel = 'manual';
            }

            $result = $walletService->recharge($userId, $amount, $channel);
            json_response($result, '充值成功');
        } else {
            json_error('无效的请求路径', 404, null, 404);
        }
        break;

    default:
        json_error('不支持的请求方法', 405, null, 405);
}
