<?php

require_once __DIR__ . '/BaseTestCase.php';

class FreezeOrderTest extends BaseTestCase {

    private function createPendingOrder($userId, $amount = 50.00, $title = '待支付订单') {
        $orderModel = new Order();
        $orderId = $orderModel->insert([
            'order_no' => $orderModel->generateOrderNo(),
            'user_id' => $userId,
            'amount' => $amount,
            'title' => $title,
            'description' => '',
            'status' => Order::STATUS_PENDING,
        ]);
        return $orderModel->findById($orderId);
    }

    public function testFreezePendingOrderManually() {
        $userId = 1;
        $order = $this->createPendingOrder($userId, 100.00, '手动冻结测试');
        $orderId = $order['id'];

        $result = $this->orderService->freezeOrder($orderId, $userId, '手动冻结原因');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['frozen']);
        $this->assertEqual(Order::STATUS_FROZEN, $result['order']['status']);
        $this->assertEqual('手动冻结原因', $result['order']['frozen_reason']);
    }

    public function testFreezeAlreadyFrozenOrderShouldReturnShortage() {
        $userId = 1;
        $order = $this->createPendingOrder($userId, 50.00, '重复冻结测试');
        $orderId = $order['id'];

        $r1 = $this->orderService->freezeOrder($orderId, $userId);
        $this->assertTrue($r1['frozen']);

        $r2 = $this->orderService->freezeOrder($orderId, $userId, '再次冻结');
        $this->assertFalse($r2['success']);
        $this->assertTrue($r2['frozen']);
        $this->assertEqual(Order::STATUS_FROZEN, $r2['order']['status']);
        $this->assertContains('已处于冻结状态', $r2['message']);
    }

    public function testFreezeNonPendingOrderShouldThrowException() {
        $userId = 1;
        $orderModel = new Order();
        $orderId = $orderModel->insert([
            'order_no' => $orderModel->generateOrderNo(),
            'user_id' => $userId,
            'amount' => 100.00,
            'title' => '已支付订单',
            'status' => Order::STATUS_PAID,
            'paid_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertException(function () use ($orderId, $userId) {
            $this->orderService->freezeOrder($orderId, $userId);
        }, 'OrderStateException', 2301);
    }

    public function testUnfreezeFrozenOrder() {
        $userId = 1;
        $order = $this->createPendingOrder($userId, 80.00, '解冻测试');
        $orderId = $order['id'];

        $freezeResult = $this->orderService->freezeOrder($orderId, $userId, '测试冻结');
        $this->assertEqual(Order::STATUS_FROZEN, $freezeResult['order']['status']);

        $unfreezeResult = $this->orderService->unfreezeOrder($orderId, $userId);
        $this->assertEqual(Order::STATUS_PENDING, $unfreezeResult['status']);
        $this->assertNull($unfreezeResult['frozen_reason']);

        $detail = $this->orderService->getOrderDetail($orderId, $userId);
        $this->assertFalse($detail['can_retry']);
        $this->assertTrue($detail['can_pay']);
    }

    public function testUnfreezeNonFrozenOrderShouldThrowException() {
        $userId = 1;
        $order = $this->createPendingOrder($userId, 50.00, '未冻结订单');
        $orderId = $order['id'];

        $this->assertException(function () use ($orderId, $userId) {
            $this->orderService->unfreezeOrder($orderId, $userId);
        }, 'OrderStateException', 2401);
    }

    public function testCancelFrozenOrder() {
        $userId = 3;
        $createResult = $this->orderService->createOrder($userId, 100.00, '取消冻结订单');
        $orderId = $createResult['order']['id'];
        $this->assertEqual(Order::STATUS_FROZEN, $createResult['order']['status']);

        $cancelResult = $this->orderService->cancelOrder($orderId, $userId);
        $this->assertEqual(Order::STATUS_CANCELLED, $cancelResult['status']);

        $this->assertException(function () use ($orderId, $userId) {
            $this->orderService->retryPayment($orderId, $userId);
        }, 'OrderStateException', 2501);
    }

    public function testWalletFreezeAndUnfreezeBalance() {
        $userId = 1;
        $walletBefore = $this->walletService->getWalletInfo($userId);
        $this->assertEqual(100.0, $walletBefore['balance']);
        $this->assertEqual(0.0, $walletBefore['frozen_amount']);
        $this->assertEqual(100.0, $walletBefore['available_balance']);

        $freezeResult = $this->walletService->freeze($userId, 30.00, null, '测试钱包冻结');
        $this->assertEqual(30.0, floatval($freezeResult['frozen_amount']));
        $this->assertEqual(70.0, floatval($freezeResult['available_after']));

        $walletMid = $this->walletService->getWalletInfo($userId);
        $this->assertEqual(100.0, $walletMid['balance']);
        $this->assertEqual(30.0, $walletMid['frozen_amount']);
        $this->assertEqual(70.0, $walletMid['available_balance']);

        $unfreezeResult = $this->walletService->unfreeze($userId, 20.00, null, '部分解冻');
        $this->assertEqual(10.0, floatval($unfreezeResult['frozen_amount']));
        $this->assertEqual(90.0, floatval($unfreezeResult['available_after']));
    }

    public function testWalletFreezeInsufficientShouldThrow() {
        $userId = 1;

        $this->assertException(function () use ($userId) {
            $this->walletService->freeze($userId, 500.00);
        }, 'InsufficientBalanceException', 1102);

        try {
            $this->walletService->freeze($userId, 500.00);
        } catch (InsufficientBalanceException $e) {
            $ctx = $e->getContext();
            $this->assertEqual(100.0, floatval($ctx['available']));
            $this->assertEqual(500.0, floatval($ctx['required']));
            $this->assertEqual(400.0, floatval($ctx['shortage']));
        }
    }

    public function testWalletUnfreezeMoreThanFrozenShouldThrow() {
        $userId = 1;
        $this->walletService->freeze($userId, 20.00);

        $this->assertException(function () use ($userId) {
            $this->walletService->unfreeze($userId, 100.00);
        }, 'InvalidArgumentException', 1202);
    }

    public function testBatchFreezeOrdersMixedStatus() {
        $userId = 1;

        $o1 = $this->createPendingOrder($userId, 10.00, '批量1');
        $o2 = $this->createPendingOrder($userId, 20.00, '批量2');
        $o3 = $this->createPendingOrder($userId, 30.00, '批量3-先冻结');

        $this->orderService->freezeOrder($o3['id'], $userId);

        $orderModel = new Order();
        $paidOrderId = $orderModel->insert([
            'order_no' => $orderModel->generateOrderNo(),
            'user_id' => $userId,
            'amount' => 50.00,
            'title' => '已支付订单',
            'status' => Order::STATUS_PAID,
            'paid_at' => date('Y-m-d H:i:s'),
        ]);

        $invalidOrderId = 99999;

        $otherUserId = 2;
        $oOther = $this->createPendingOrder($otherUserId, 99.00, '他人订单');

        $batchResult = $this->orderService->batchFreezeOrders(
            [$o1['id'], $o2['id'], $o3['id'], $paidOrderId, $invalidOrderId, $oOther['id']],
            $userId,
            '批量冻结原因'
        );

        $this->assertEqual(6, $batchResult['total_count']);
        $this->assertEqual(3, $batchResult['success_count']);
        $this->assertEqual(3, $batchResult['failed_count']);
        $this->assertEqual(1, $batchResult['skipped_count']);
        $this->assertEqual(3, count($batchResult['success_ids']));

        $this->assertContains($o1['id'], $batchResult['success_ids']);
        $this->assertContains($o2['id'], $batchResult['success_ids']);
        $this->assertContains($o3['id'], $batchResult['success_ids']);

        $skippedIds = array_column($batchResult['skipped_items'], 'order_id');
        $this->assertContains($o3['id'], $skippedIds);

        $failedOrderNos = array_column($batchResult['failed_items'], 'order_id');
        $this->assertContains($paidOrderId, $failedOrderNos);
        $this->assertContains($invalidOrderId, $failedOrderNos);
        $this->assertContains($oOther['id'], $failedOrderNos);
    }

    public function testFreezeOrderPermissionDenied() {
        $userId1 = 1;
        $userId2 = 2;
        $order = $this->createPendingOrder($userId1, 100.00, '权限测试订单');

        $this->assertException(function () use ($order, $userId2) {
            $this->orderService->freezeOrder($order['id'], $userId2);
        }, 'OrderPermissionDeniedException', 2002);
    }

    public function testFrozenReasonPersistedInDatabase() {
        $userId = 1;
        $reason = '风险订单-需要人工审核-' . time();
        $order = $this->createPendingOrder($userId, 500.00, '原因持久化');
        $orderId = $order['id'];

        $this->orderService->freezeOrder($orderId, $userId, $reason);

        $dbOrder = TestHelper::rawFetch("SELECT * FROM orders WHERE id = ?", [$orderId]);
        $this->assertEqual(Order::STATUS_FROZEN, $dbOrder['status']);
        $this->assertEqual($reason, $dbOrder['frozen_reason']);

        $this->orderService->unfreezeOrder($orderId, $userId);
        $dbOrder2 = TestHelper::rawFetch("SELECT * FROM orders WHERE id = ?", [$orderId]);
        $this->assertEqual(Order::STATUS_PENDING, $dbOrder2['status']);
        $this->assertNull($dbOrder2['frozen_reason']);
    }

    public function testFreezeAndDeductFromFrozenAtomic() {
        $userId = 1;
        $walletBefore = $this->walletService->getWalletInfo($userId);
        $this->assertEqual(100.0, $walletBefore['balance']);

        $result = $this->walletService->freeze($userId, 40.00);
        $this->assertEqual(40.0, floatval($result['frozen_amount']));

        $deduct = $this->walletService->deductFromFrozen($userId, 40.00);
        $this->assertEqual(60.0, floatval($deduct['balance_after']));
        $this->assertEqual(0.0, floatval($deduct['frozen_after']));
        $this->assertEqual(60.0, floatval($deduct['available_after']));

        $walletFinal = $this->walletService->getWalletInfo($userId);
        $this->assertEqual(60.0, $walletFinal['balance']);
        $this->assertEqual(0.0, $walletFinal['frozen_amount']);

        $txns = $this->walletService->getTransactions($userId, 10);
        $types = array_column($txns, 'type');
        $this->assertContains('freeze', $types);
        $this->assertContains('payment', $types);
    }
}
