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

    private function beginTransaction() {
        return $this->orderModel->beginTransaction();
    }

    private function commit() {
        return $this->orderModel->commit();
    }

    private function rollBack() {
        return $this->orderModel->rollBack();
    }

    public function createOrder($userId, $amount, $title, $description = '') {
        if ($amount <= 0) {
            throw new Exception('订单金额必须大于0');
        }

        if (empty($title)) {
            throw new Exception('订单标题不能为空');
        }

        $this->beginTransaction();

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

            $this->commit();

            $order = $this->orderModel->findById($orderId);

            return $this->processPayment($orderId, $userId);
        } catch (Exception $e) {
            $this->rollBack();
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
            if ($order['status'] === Order::STATUS_FROZEN) {
                return [
                    'success' => false,
                    'order' => $order,
                    'wallet' => $walletInfo,
                    'frozen' => true,
                    'shortage' => $amount - $availableBalance,
                    'message' => '余额不足，订单已冻结，请充值后重试',
                ];
            }
            return $this->freezeOrder($orderId, $userId, '余额不足，订单已冻结，请充值后重试');
        }

        $this->beginTransaction();

        try {
            $this->walletService->freeze($userId, $amount, $orderId, '订单支付冻结');

            $this->walletService->deductFromFrozen($userId, $amount, $orderId, '订单支付扣款');

            $this->orderModel->updateStatus($orderId, Order::STATUS_PAID, [
                'frozen_reason' => null,
            ]);

            $this->commit();

            $order = $this->orderModel->findById($orderId);
            $walletInfo = $this->walletService->getWalletInfo($userId);

            return [
                'success' => true,
                'order' => $order,
                'wallet' => $walletInfo,
                'message' => '支付成功',
            ];
        } catch (Exception $e) {
            $this->rollBack();
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

        $amount = floatval($order['amount']);
        $walletInfo = $this->walletService->getWalletInfo($userId);

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

        $this->beginTransaction();
        try {
            $this->walletService->freeze($userId, $amount, $orderId, '补款支付冻结');
            $this->walletService->deductFromFrozen($userId, $amount, $orderId, '补款支付扣款');
            $this->orderModel->updateStatus($orderId, Order::STATUS_PAID, [
                'frozen_reason' => null,
            ]);
            $this->commit();
        } catch (Exception $e) {
            $this->rollBack();
            throw $e;
        }

        $order = $this->orderModel->findById($orderId);
        $walletInfo = $this->walletService->getWalletInfo($userId);

        return [
            'success' => true,
            'order' => $order,
            'wallet' => $walletInfo,
            'message' => '支付成功',
        ];
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

        $rechargeResult = $this->walletService->recharge($userId, $rechargeAmount);

        $paymentResult = $this->retryPayment($orderId, $userId);

        return [
            'success' => $paymentResult['success'],
            'recharge' => $rechargeResult,
            'order' => $paymentResult['order'],
            'wallet' => $paymentResult['wallet'],
            'frozen' => isset($paymentResult['frozen']) ? $paymentResult['frozen'] : false,
            'shortage' => isset($paymentResult['shortage']) ? $paymentResult['shortage'] : 0,
            'message' => $paymentResult['message'],
        ];
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

    public function batchFreezeOrders($orderIds, $userId, $reason = '') {
        if (!is_array($orderIds) || empty($orderIds)) {
            throw new Exception('订单ID列表不能为空');
        }

        $successIds = [];
        $failedItems = [];
        $successOrders = [];

        foreach ($orderIds as $orderId) {
            try {
                $order = $this->orderModel->findById($orderId);

                if (!$order) {
                    $failedItems[] = [
                        'order_id' => $orderId,
                        'order_no' => '',
                        'title' => '',
                        'reason' => '订单不存在'
                    ];
                    continue;
                }

                if ($order['user_id'] != $userId) {
                    $failedItems[] = [
                        'order_id' => $orderId,
                        'order_no' => $order['order_no'],
                        'title' => $order['title'],
                        'reason' => '无权操作此订单'
                    ];
                    continue;
                }

                if ($order['status'] === Order::STATUS_FROZEN) {
                    $failedItems[] = [
                        'order_id' => $orderId,
                        'order_no' => $order['order_no'],
                        'title' => $order['title'],
                        'reason' => '订单已处于冻结状态'
                    ];
                    continue;
                }

                if ($order['status'] !== Order::STATUS_PENDING) {
                    $failedItems[] = [
                        'order_id' => $orderId,
                        'order_no' => $order['order_no'],
                        'title' => $order['title'],
                        'reason' => '当前订单状态无法冻结'
                    ];
                    continue;
                }

                $this->orderModel->updateStatus($orderId, Order::STATUS_FROZEN, [
                    'frozen_reason' => $reason ?: '批量冻结'
                ]);

                $frozenOrder = $this->orderModel->findById($orderId);
                $successIds[] = $orderId;
                $successOrders[] = $frozenOrder;
            } catch (Exception $e) {
                $failedItems[] = [
                    'order_id' => $orderId,
                    'order_no' => $order['order_no'] ?? '',
                    'title' => $order['title'] ?? '',
                    'reason' => $e->getMessage()
                ];
            }
        }

        $walletInfo = $this->walletService->getWalletInfo($userId);

        return [
            'success_count' => count($successIds),
            'failed_count' => count($failedItems),
            'total_count' => count($orderIds),
            'success_ids' => $successIds,
            'success_orders' => $successOrders,
            'failed_items' => $failedItems,
            'wallet' => $walletInfo,
            'message' => count($successIds) > 0 ? '批量冻结完成' : '批量冻结失败'
        ];
    }

    public function batchRetryPayment($orderIds, $userId) {
        if (!is_array($orderIds) || empty($orderIds)) {
            throw new Exception('订单ID列表不能为空');
        }

        $successIds = [];
        $failedItems = [];
        $successOrders = [];
        $stillFrozenItems = [];
        $candidates = [];

        foreach ($orderIds as $orderId) {
            try {
                $order = $this->orderModel->findById($orderId);

                if (!$order) {
                    $failedItems[] = [
                        'order_id' => $orderId,
                        'order_no' => '',
                        'title' => '',
                        'amount' => 0,
                        'reason' => '订单不存在'
                    ];
                    continue;
                }

                if ($order['user_id'] != $userId) {
                    $failedItems[] = [
                        'order_id' => $orderId,
                        'order_no' => $order['order_no'],
                        'title' => $order['title'],
                        'amount' => floatval($order['amount']),
                        'reason' => '无权操作此订单'
                    ];
                    continue;
                }

                if ($order['status'] !== Order::STATUS_FROZEN) {
                    $failedItems[] = [
                        'order_id' => $orderId,
                        'order_no' => $order['order_no'],
                        'title' => $order['title'],
                        'amount' => floatval($order['amount']),
                        'reason' => '只有冻结状态的订单才能补款恢复'
                    ];
                    continue;
                }

                $candidates[] = $order;
            } catch (Exception $e) {
                $failedItems[] = [
                    'order_id' => $orderId,
                    'order_no' => $order['order_no'] ?? '',
                    'title' => $order['title'] ?? '',
                    'amount' => isset($order['amount']) ? floatval($order['amount']) : 0,
                    'reason' => $e->getMessage()
                ];
            }
        }

        usort($candidates, function ($a, $b) {
            return floatval($a['amount']) <=> floatval($b['amount']);
        });

        foreach ($candidates as $order) {
            try {
                $walletInfo = $this->walletService->getWalletInfo($userId);
                $amount = floatval($order['amount']);
                $orderId = intval($order['id']);

                if ($walletInfo['available_balance'] < $amount) {
                    $stillFrozenItems[] = [
                        'order_id' => $orderId,
                        'order_no' => $order['order_no'],
                        'title' => $order['title'],
                        'amount' => $amount,
                        'reason' => '余额不足，无法支付此订单'
                    ];
                    continue;
                }

                $this->beginTransaction();
                try {
                    $this->walletService->freeze($userId, $amount, $orderId, '补款支付冻结');
                    $this->walletService->deductFromFrozen($userId, $amount, $orderId, '补款支付扣款');
                    $this->orderModel->updateStatus($orderId, Order::STATUS_PAID, [
                        'frozen_reason' => null
                    ]);
                    $this->commit();

                    $paidOrder = $this->orderModel->findById($orderId);
                    $successIds[] = $orderId;
                    $successOrders[] = $paidOrder;
                } catch (Exception $innerE) {
                    $this->rollBack();
                    $failedItems[] = [
                        'order_id' => $orderId,
                        'order_no' => $order['order_no'],
                        'title' => $order['title'],
                        'amount' => $amount,
                        'reason' => $innerE->getMessage()
                    ];
                }
            } catch (Exception $e) {
                $failedItems[] = [
                    'order_id' => $order['id'],
                    'order_no' => $order['order_no'] ?? '',
                    'title' => $order['title'] ?? '',
                    'amount' => floatval($order['amount']),
                    'reason' => $e->getMessage()
                ];
            }
        }

        $walletFinal = $this->walletService->getWalletInfo($userId);
        $totalFrozenAmount = array_reduce($stillFrozenItems, function ($sum, $item) {
            return $sum + $item['amount'];
        }, 0);
        $totalStillFrozen = max(0, $totalFrozenAmount - $walletFinal['available_balance']);

        return [
            'success_count' => count($successIds),
            'failed_count' => count($failedItems),
            'frozen_count' => count($stillFrozenItems),
            'total_count' => count($orderIds),
            'success_ids' => $successIds,
            'success_orders' => $successOrders,
            'failed_items' => $failedItems,
            'still_frozen_items' => $stillFrozenItems,
            'total_frozen_amount' => $totalFrozenAmount,
            'total_still_frozen_amount' => $totalStillFrozen,
            'available_balance' => $walletFinal['available_balance'],
            'wallet' => $walletFinal,
            'message' => count($successIds) > 0 ? '批量补款恢复完成' : '批量补款恢复失败'
        ];
    }
}
