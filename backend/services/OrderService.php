<?php

require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/WalletService.php';

class OrderService {
    private $orderModel;
    private $walletService;

    public function __construct() {
        $this->orderModel = new Order();
        $this->walletService = new WalletService();
    }

    public function createOrder($userId, $amount, $title, $description = '') {
        if ($amount <= 0) {
            throw new Exception('订单金额必须大于0');
        }

        if (empty($title)) {
            throw new Exception('订单标题不能为空');
        }

        $this->orderModel->getConnection()->beginTransaction();

        try {
            $orderNo = $this->orderModel->generateOrderNo();

            $orderId = $this->orderModel->insert([
                'order_no' => $orderNo,
                'user_id' => $userId,
                'amount' => $amount,
                'title' => $title,
                'description' => $description,
                'status' => Order::STATUS_PENDING,
            ]);

            $this->orderModel->getConnection()->commit();

            $order = $this->orderModel->findById($orderId);

            return $this->processPayment($orderId, $userId);
        } catch (Exception $e) {
            $this->orderModel->getConnection()->rollBack();
            throw $e;
        }
    }

    public function processPayment($orderId, $userId) {
        $order = $this->orderModel->findById($orderId);

        if (!$order) {
            throw new Exception('订单不存在');
        }

        if ($order['status'] !== Order::STATUS_PENDING && $order['status'] !== Order::STATUS_FROZEN) {
            throw new Exception('当前订单状态不支持支付');
        }

        $amount = floatval($order['amount']);

        $walletInfo = $this->walletService->getWalletInfo($userId);
        $availableBalance = $walletInfo['available_balance'];

        if ($availableBalance < $amount) {
            return $this->freezeOrder($orderId, $userId, '余额不足，订单已冻结，请充值后重试');
        }

        $this->orderModel->getConnection()->beginTransaction();

        try {
            $this->walletService->freeze($userId, $amount, $orderId, '订单支付冻结');

            $this->walletService->deductFromFrozen($userId, $amount, $orderId, '订单支付扣款');

            $this->orderModel->updateStatus($orderId, Order::STATUS_PAID, [
                'frozen_reason' => null,
            ]);

            $this->orderModel->getConnection()->commit();

            $order = $this->orderModel->findById($orderId);
            $walletInfo = $this->walletService->getWalletInfo($userId);

            return [
                'success' => true,
                'order' => $order,
                'wallet' => $walletInfo,
                'message' => '支付成功',
            ];
        } catch (Exception $e) {
            $this->orderModel->getConnection()->rollBack();
            throw $e;
        }
    }

    public function freezeOrder($orderId, $userId, $reason = '') {
        $order = $this->orderModel->findById($orderId);

        if (!$order) {
            throw new Exception('订单不存在');
        }

        if ($order['user_id'] != $userId) {
            throw new Exception('无权操作此订单');
        }

        if ($order['status'] === Order::STATUS_FROZEN) {
            $walletInfo = $this->walletService->getWalletInfo($userId);
            return [
                'success' => false,
                'order' => $order,
                'wallet' => $walletInfo,
                'frozen' => true,
                'message' => $reason ?: '订单已处于冻结状态',
            ];
        }

        if ($order['status'] !== Order::STATUS_PENDING) {
            throw new Exception('当前订单状态无法冻结');
        }

        $this->orderModel->updateStatus($orderId, Order::STATUS_FROZEN, [
            'frozen_reason' => $reason,
        ]);

        $order = $this->orderModel->findById($orderId);
        $walletInfo = $this->walletService->getWalletInfo($userId);

        return [
            'success' => false,
            'order' => $order,
            'wallet' => $walletInfo,
            'frozen' => true,
            'shortage' => floatval($order['amount']) - $walletInfo['available_balance'],
            'message' => $reason ?: '余额不足，订单已冻结',
        ];
    }

    public function unfreezeOrder($orderId, $userId) {
        $order = $this->orderModel->findById($orderId);

        if (!$order) {
            throw new Exception('订单不存在');
        }

        if ($order['user_id'] != $userId) {
            throw new Exception('无权操作此订单');
        }

        if ($order['status'] !== Order::STATUS_FROZEN) {
            throw new Exception('订单未冻结，无需解冻');
        }

        $this->orderModel->updateStatus($orderId, Order::STATUS_PENDING, [
            'frozen_reason' => null,
        ]);

        return $this->orderModel->findById($orderId);
    }

    public function retryPayment($orderId, $userId) {
        $order = $this->orderModel->findById($orderId);

        if (!$order) {
            throw new Exception('订单不存在');
        }

        if ($order['user_id'] != $userId) {
            throw new Exception('无权操作此订单');
        }

        if ($order['status'] !== Order::STATUS_FROZEN) {
            throw new Exception('只有冻结状态的订单才能重试支付');
        }

        $walletInfo = $this->walletService->getWalletInfo($userId);
        $amount = floatval($order['amount']);

        if ($walletInfo['available_balance'] < $amount) {
            return [
                'success' => false,
                'order' => $order,
                'wallet' => $walletInfo,
                'frozen' => true,
                'shortage' => $amount - $walletInfo['available_balance'],
                'message' => '余额仍然不足，请继续充值',
            ];
        }

        $this->orderModel->updateStatus($orderId, Order::STATUS_PENDING, [
            'frozen_reason' => null,
        ]);

        return $this->processPayment($orderId, $userId);
    }

    public function rechargeAndRetry($orderId, $userId, $rechargeAmount) {
        if ($rechargeAmount <= 0) {
            throw new Exception('充值金额必须大于0');
        }

        $order = $this->orderModel->findById($orderId);
        if (!$order) {
            throw new Exception('订单不存在');
        }

        if ($order['user_id'] != $userId) {
            throw new Exception('无权操作此订单');
        }

        if ($order['status'] !== Order::STATUS_FROZEN) {
            throw new Exception('只有冻结状态的订单才能充值补款');
        }

        $this->orderModel->getConnection()->beginTransaction();

        try {
            $rechargeResult = $this->walletService->recharge($userId, $rechargeAmount);

            $paymentResult = $this->retryPayment($orderId, $userId);

            $this->orderModel->getConnection()->commit();

            return [
                'success' => $paymentResult['success'],
                'recharge' => $rechargeResult,
                'order' => $paymentResult['order'],
                'wallet' => $paymentResult['wallet'],
                'frozen' => isset($paymentResult['frozen']) ? $paymentResult['frozen'] : false,
                'shortage' => isset($paymentResult['shortage']) ? $paymentResult['shortage'] : 0,
                'message' => $paymentResult['message'],
            ];
        } catch (Exception $e) {
            $this->orderModel->getConnection()->rollBack();
            throw $e;
        }
    }

    public function cancelOrder($orderId, $userId) {
        $order = $this->orderModel->findById($orderId);

        if (!$order) {
            throw new Exception('订单不存在');
        }

        if ($order['user_id'] != $userId) {
            throw new Exception('无权操作此订单');
        }

        if (!in_array($order['status'], [Order::STATUS_PENDING, Order::STATUS_FROZEN])) {
            throw new Exception('当前订单状态无法取消');
        }

        $this->orderModel->updateStatus($orderId, Order::STATUS_CANCELLED);

        return $this->orderModel->findById($orderId);
    }

    public function getOrderDetail($orderId, $userId) {
        $order = $this->orderModel->findById($orderId);

        if (!$order) {
            throw new Exception('订单不存在');
        }

        if ($order['user_id'] != $userId) {
            throw new Exception('无权查看此订单');
        }

        $walletInfo = $this->walletService->getWalletInfo($userId);
        $amount = floatval($order['amount']);
        $shortage = max(0, $amount - $walletInfo['available_balance']);

        return [
            'order' => $order,
            'wallet' => $walletInfo,
            'can_retry' => $order['status'] === Order::STATUS_FROZEN,
            'shortage' => $shortage,
            'suggest_recharge' => $shortage > 0 ? ceil($shortage) : 0,
        ];
    }

    public function getOrderList($userId, $status = null) {
        return $this->orderModel->getListByUserId($userId, $status);
    }

    public function getFrozenOrders($userId) {
        return $this->orderModel->getFrozenOrders($userId);
    }

    public function completeOrder($orderId, $userId) {
        $order = $this->orderModel->findById($orderId);

        if (!$order) {
            throw new Exception('订单不存在');
        }

        if ($order['user_id'] != $userId) {
            throw new Exception('无权操作此订单');
        }

        if ($order['status'] !== Order::STATUS_PAID) {
            throw new Exception('只有已支付订单才能完成');
        }

        $this->orderModel->updateStatus($orderId, Order::STATUS_COMPLETED);

        return $this->orderModel->findById($orderId);
    }
}
