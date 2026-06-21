<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../services/OrderService.php';

$orderService = new OrderService();

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['action'] ?? '';

try {
    $userId = get_current_user_id();

    switch ($method) {
        case 'GET':
            if ($path === 'list') {
                $status = $_GET['status'] ?? null;
                $orders = $orderService->getOrderList($userId, $status);
                json_response($orders);
            } elseif ($path === 'frozen') {
                $orders = $orderService->getFrozenOrders($userId);
                json_response($orders);
            } elseif ($path === 'detail') {
                $orderId = $_GET['id'] ?? 0;
                if (!$orderId) {
                    json_error('订单ID不能为空', 400);
                }
                $detail = $orderService->getOrderDetail($orderId, $userId);
                json_response($detail);
            } else {
                json_error('无效的请求路径', 404);
            }
            break;

        case 'POST':
            $data = get_request_data();

            if ($path === 'create') {
                $amount = floatval($data['amount'] ?? 0);
                $title = $data['title'] ?? '';
                $description = $data['description'] ?? '';

                if ($amount <= 0) {
                    json_error('订单金额必须大于0', 400);
                }
                if (empty($title)) {
                    json_error('订单标题不能为空', 400);
                }

                $result = $orderService->createOrder($userId, $amount, $title, $description);
                json_response($result, $result['success'] ? '订单创建成功' : $result['message']);
            } elseif ($path === 'retry') {
                $orderId = intval($data['order_id'] ?? 0);
                if (!$orderId) {
                    json_error('订单ID不能为空', 400);
                }

                $result = $orderService->retryPayment($orderId, $userId);
                json_response($result, $result['success'] ? '支付成功' : $result['message']);
            } elseif ($path === 'recharge-retry') {
                $orderId = intval($data['order_id'] ?? 0);
                $rechargeAmount = floatval($data['recharge_amount'] ?? 0);

                if (!$orderId) {
                    json_error('订单ID不能为空', 400);
                }
                if ($rechargeAmount <= 0) {
                    json_error('充值金额必须大于0', 400);
                }

                $result = $orderService->rechargeAndRetry($orderId, $userId, $rechargeAmount);
                json_response($result, $result['success'] ? '充值并支付成功' : $result['message']);
            } elseif ($path === 'cancel') {
                $orderId = intval($data['order_id'] ?? 0);
                if (!$orderId) {
                    json_error('订单ID不能为空', 400);
                }

                $order = $orderService->cancelOrder($orderId, $userId);
                json_response($order, '订单已取消');
            } elseif ($path === 'freeze') {
                $orderId = intval($data['order_id'] ?? 0);
                $reason = $data['reason'] ?? '';

                if (!$orderId) {
                    json_error('订单ID不能为空', 400);
                }

                $result = $orderService->freezeOrder($orderId, $userId, $reason);
                json_response($result, '订单已冻结');
            } elseif ($path === 'complete') {
                $orderId = intval($data['order_id'] ?? 0);
                if (!$orderId) {
                    json_error('订单ID不能为空', 400);
                }

                $order = $orderService->completeOrder($orderId, $userId);
                json_response($order, '订单已完成');
            } elseif ($path === 'batch-freeze') {
                $orderIds = $data['order_ids'] ?? [];
                $reason = $data['reason'] ?? '';

                if (!is_array($orderIds) || empty($orderIds)) {
                    json_error('订单ID列表不能为空', 400);
                }

                $orderIds = array_map('intval', $orderIds);

                $result = $orderService->batchFreezeOrders($orderIds, $userId, $reason);
                json_response($result, $result['message']);
            } elseif ($path === 'batch-retry') {
                $orderIds = $data['order_ids'] ?? [];

                if (!is_array($orderIds) || empty($orderIds)) {
                    json_error('订单ID列表不能为空', 400);
                }

                $orderIds = array_map('intval', $orderIds);

                $result = $orderService->batchRetryPayment($orderIds, $userId);
                json_response($result, $result['message']);
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
