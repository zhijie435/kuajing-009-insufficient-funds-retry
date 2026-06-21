<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../services/OrderService.php';

$orderService = new OrderService();

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['action'] ?? '';

$userId = get_current_user_id();

switch ($method) {
    case 'GET':
        if ($path === 'list') {
            $status = isset($_GET['status']) ? trim(strval($_GET['status'])) : null;
            if ($status === '') {
                $status = null;
            }
            $orders = $orderService->getOrderList($userId, $status);
            json_response($orders);
        } elseif ($path === 'frozen') {
            $orders = $orderService->getFrozenOrders($userId);
            json_response($orders);
        } elseif ($path === 'detail') {
            $orderId = isset($_GET['id']) ? intval($_GET['id']) : 0;
            if (!$orderId) {
                json_error('订单ID不能为空', 400, null, 400);
            }
            $detail = $orderService->getOrderDetail($orderId, $userId);
            json_response($detail);
        } elseif ($path === 'frozen-summary') {
            $summary = $orderService->getFrozenSummary($userId);
            json_response($summary);
        } else {
            json_error('无效的请求路径', 404, null, 404);
        }
        break;

    case 'POST':
        $data = get_request_data();

        if ($path === 'create') {
            $amount = isset($data['amount']) ? floatval($data['amount']) : 0;
            $title = isset($data['title']) ? trim(strval($data['title'])) : '';
            $description = isset($data['description']) ? trim(strval($data['description'])) : '';

            if ($amount <= 0) {
                json_error('订单金额必须大于0', 400, null, 400);
            }
            if ($amount > 9999999.99) {
                json_error('订单金额超出限制', 400, null, 400);
            }
            if ($title === '') {
                json_error('订单标题不能为空', 400, null, 400);
            }
            if (mb_strlen($title) > 200) {
                json_error('订单标题过长（最大200字符）', 400, null, 400);
            }
            if (mb_strlen($description) > 2000) {
                json_error('订单描述过长（最大2000字符）', 400, null, 400);
            }

            $result = $orderService->createOrder($userId, $amount, $title, $description);
            json_response($result, $result['success'] ? '订单创建成功，支付完成' : $result['message']);
        } elseif ($path === 'retry') {
            $orderId = isset($data['order_id']) ? intval($data['order_id']) : 0;
            if (!$orderId) {
                json_error('订单ID不能为空', 400, null, 400);
            }

            $result = $orderService->retryPayment($orderId, $userId);
            json_response($result, $result['success'] ? '支付成功' : $result['message']);
        } elseif ($path === 'recharge-retry') {
            $orderId = isset($data['order_id']) ? intval($data['order_id']) : 0;
            $rechargeAmount = isset($data['recharge_amount']) ? floatval($data['recharge_amount']) : 0;
            $channel = isset($data['channel']) ? trim(strval($data['channel'])) : 'manual';

            if (!$orderId) {
                json_error('订单ID不能为空', 400, null, 400);
            }
            if ($rechargeAmount <= 0) {
                json_error('充值金额必须大于0', 400, null, 400);
            }
            if ($rechargeAmount > 9999999.99) {
                json_error('充值金额超出限制', 400, null, 400);
            }

            $allowedChannels = ['manual', 'wechat', 'alipay', 'bank_transfer'];
            if (!in_array($channel, $allowedChannels, true)) {
                $channel = 'manual';
            }

            $result = $orderService->rechargeAndRetry($orderId, $userId, $rechargeAmount, $channel);
            json_response($result, $result['success'] ? '充值并支付成功' : $result['message']);
        } elseif ($path === 'cancel') {
            $orderId = isset($data['order_id']) ? intval($data['order_id']) : 0;
            if (!$orderId) {
                json_error('订单ID不能为空', 400, null, 400);
            }

            $order = $orderService->cancelOrder($orderId, $userId);
            json_response($order, '订单已取消');
        } elseif ($path === 'freeze') {
            $orderId = isset($data['order_id']) ? intval($data['order_id']) : 0;
            $reason = isset($data['reason']) ? trim(strval($data['reason'])) : '';

            if (!$orderId) {
                json_error('订单ID不能为空', 400, null, 400);
            }
            if (mb_strlen($reason) > 500) {
                json_error('冻结原因过长', 400, null, 400);
            }

            $result = $orderService->freezeOrder($orderId, $userId, $reason);
            json_response($result, $result['frozen'] ? $result['message'] : '操作完成');
        } elseif ($path === 'unfreeze') {
            $orderId = isset($data['order_id']) ? intval($data['order_id']) : 0;
            if (!$orderId) {
                json_error('订单ID不能为空', 400, null, 400);
            }

            $order = $orderService->unfreezeOrder($orderId, $userId);
            json_response($order, '订单已解冻');
        } elseif ($path === 'complete') {
            $orderId = isset($data['order_id']) ? intval($data['order_id']) : 0;
            if (!$orderId) {
                json_error('订单ID不能为空', 400, null, 400);
            }

            $order = $orderService->completeOrder($orderId, $userId);
            json_response($order, '订单已完成');
        } elseif ($path === 'batch-freeze') {
            $orderIds = isset($data['order_ids']) ? $data['order_ids'] : [];
            $reason = isset($data['reason']) ? trim(strval($data['reason'])) : '';

            if (!is_array($orderIds) || empty($orderIds)) {
                json_error('订单ID列表不能为空', 400, null, 400);
            }
            if (count($orderIds) > 500) {
                json_error('单次批量操作不能超过500条', 400, null, 400);
            }
            if (mb_strlen($reason) > 500) {
                json_error('冻结原因过长', 400, null, 400);
            }

            $orderIds = array_values(array_filter(array_map('intval', $orderIds), function ($v) {
                return $v > 0;
            }));

            if (empty($orderIds)) {
                json_error('有效的订单ID列表为空', 400, null, 400);
            }

            $result = $orderService->batchFreezeOrders($orderIds, $userId, $reason);
            json_response($result, $result['message']);
        } elseif ($path === 'batch-retry') {
            $orderIds = isset($data['order_ids']) ? $data['order_ids'] : [];

            if (!is_array($orderIds) || empty($orderIds)) {
                json_error('订单ID列表不能为空', 400, null, 400);
            }
            if (count($orderIds) > 500) {
                json_error('单次批量操作不能超过500条', 400, null, 400);
            }

            $orderIds = array_values(array_filter(array_map('intval', $orderIds), function ($v) {
                return $v > 0;
            }));

            if (empty($orderIds)) {
                json_error('有效的订单ID列表为空', 400, null, 400);
            }

            $result = $orderService->batchRetryPayment($orderIds, $userId);
            json_response($result, $result['message']);
        } else {
            json_error('无效的请求路径', 404, null, 404);
        }
        break;

    default:
        json_error('不支持的请求方法', 405, null, 405);
}
