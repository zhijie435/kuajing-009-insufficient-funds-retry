<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../services/WalletService.php';
require_once __DIR__ . '/../models/User.php';

$walletService = new WalletService();
$userModel = new User();

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['action'] ?? '';

try {
    $userId = get_current_user_id();
    $user = $userModel->findById($userId);

    switch ($method) {
        case 'GET':
            if ($path === 'info') {
                $walletInfo = $walletService->getWalletInfo($userId);
                json_response([
                    'user' => $user,
                    'wallet' => $walletInfo,
                ]);
            } elseif ($path === 'transactions') {
                $limit = $_GET['limit'] ?? 20;
                $transactions = $walletService->getTransactions($userId, $limit);
                json_response($transactions);
            } elseif ($path === 'recharge-records') {
                $limit = $_GET['limit'] ?? 20;
                $records = $walletService->getRechargeRecords($userId, $limit);
                json_response($records);
            } else {
                json_error('无效的请求路径', 404);
            }
            break;

        case 'POST':
            $data = get_request_data();

            if ($path === 'recharge') {
                $amount = floatval($data['amount'] ?? 0);
                $channel = $data['channel'] ?? 'manual';

                if ($amount <= 0) {
                    json_error('充值金额必须大于0', 400);
                }

                $result = $walletService->recharge($userId, $amount, $channel);
                json_response($result, '充值成功');
            } else {
                json_error('无效的请求路径', 404);
            }
            break;

        default:
            json_error('不支持的请求方法', 405);
    }
} catch (Exception $e) {
    json_error($e->getMessage(), 500);
}
