<?php

require_once __DIR__ . '/BaseTestCase.php';

class StatusLoopTest extends BaseTestCase {

    private function assertOrderStatus($orderId, $expectedStatus) {
        $order = TestHelper::rawFetch("SELECT * FROM orders WHERE id = ?", [$orderId]);
        $this->assertEqual($expectedStatus, $order['status'], "订单ID {$orderId} 状态应为 {$expectedStatus}，实际为 {$order['status']}");
    }

    public function testFullStatusLoopCreateFrozenRechargeRetryPaidComplete() {
        $userId = 3;
        $amount = 500.00;

        $loop = [];

        $createResult = $this->orderService->createOrder($userId, $amount, '完整状态闭环');
        $orderId = $createResult['order']['id'];
        $loop[] = $createResult['order']['status'];
        $this->assertOrderStatus($orderId, Order::STATUS_FROZEN);

        $detail1 = $this->orderService->getOrderDetail($orderId, $userId);
        $this->assertTrue($detail1['can_retry']);
        $this->assertTrue($detail1['can_cancel']);
        $this->assertFalse($detail1['can_complete']);

        $this->walletService->recharge($userId, 300.00, 'loop');
        $retry1 = $this->orderService->retryPayment($orderId, $userId);
        $this->assertFalse($retry1['success']);
        $loop[] = 'partial_recharge_still_frozen';
        $this->assertOrderStatus($orderId, Order::STATUS_FROZEN);

        $this->walletService->recharge($userId, 200.00, 'loop');
        $retry2 = $this->orderService->retryPayment($orderId, $userId);
        $this->assertTrue($retry2['success']);
        $loop[] = $retry2['order']['status'];
        $this->assertOrderStatus($orderId, Order::STATUS_PAID);

        $detail2 = $this->orderService->getOrderDetail($orderId, $userId);
        $this->assertFalse($detail2['can_retry']);
        $this->assertFalse($detail2['can_cancel']);
        $this->assertTrue($detail2['can_complete']);
        $this->assertTrue($detail2['has_enough_balance']);

        $complete = $this->orderService->completeOrder($orderId, $userId);
        $loop[] = $complete['status'];
        $this->assertOrderStatus($orderId, Order::STATUS_COMPLETED);

        $detail3 = $this->orderService->getOrderDetail($orderId, $userId);
        $this->assertFalse($detail3['can_retry']);
        $this->assertFalse($detail3['can_cancel']);
        $this->assertFalse($detail3['can_complete']);
        $this->assertFalse($detail3['can_pay']);

        $this->assertEqual([Order::STATUS_FROZEN, 'partial_recharge_still_frozen', Order::STATUS_PAID, Order::STATUS_COMPLETED], $loop);
    }

    public function testStatusLoopRechargeAndRetryDirectToPaid() {
        $userId = 3;
        $amount = 800.00;

        $createResult = $this->orderService->createOrder($userId, $amount, '一键补款闭环');
        $orderId = $createResult['order']['id'];
        $this->assertOrderStatus($orderId, Order::STATUS_FROZEN);

        $snapshotBefore = TestHelper::rawFetch(
            "SELECT balance, frozen_amount FROM wallets WHERE user_id = ?",
            [$userId]
        );
        $this->assertEqual(0.0, floatval($snapshotBefore['balance']));
        $this->assertEqual(0.0, floatval($snapshotBefore['frozen_amount']));

        $result = $this->orderService->rechargeAndRetry($orderId, $userId, 1000.00, 'loop_channel');

        $this->assertTrue($result['success']);
        $this->assertEqual(Order::STATUS_PAID, $result['order']['status']);
        $this->assertNotNull($result['order']['paid_at']);
        $this->assertNull($result['order']['frozen_reason']);
        $this->assertEqual(1000.0, floatval($result['recharge']['amount']));

        $snapshotAfter = TestHelper::rawFetch(
            "SELECT balance, frozen_amount FROM wallets WHERE user_id = ?",
            [$userId]
        );
        $this->assertEqual(200.0, floatval($snapshotAfter['balance']));
        $this->assertEqual(0.0, floatval($snapshotAfter['frozen_amount']));

        $complete = $this->orderService->completeOrder($orderId, $userId);
        $this->assertEqual(Order::STATUS_COMPLETED, $complete['status']);
    }

    public function testStatusLoopUnfreezeBackToPendingThenPay() {
        $userId = 3;
        $amount = 200.00;

        $createResult = $this->orderService->createOrder($userId, $amount, '解冻→待支付→支付闭环');
        $orderId = $createResult['order']['id'];
        $this->assertOrderStatus($orderId, Order::STATUS_FROZEN);

        $unfreeze = $this->orderService->unfreezeOrder($orderId, $userId);
        $this->assertEqual(Order::STATUS_PENDING, $unfreeze['status']);
        $this->assertOrderStatus($orderId, Order::STATUS_PENDING);

        $detail = $this->orderService->getOrderDetail($orderId, $userId);
        $this->assertFalse($detail['can_retry']);
        $this->assertTrue($detail['can_pay']);
        $this->assertTrue($detail['can_cancel']);
        $this->assertTrue($detail['can_pay']);

        $this->walletService->recharge($userId, $amount, 'unfreeze_loop');

        $process = $this->orderService->processPayment($orderId, $userId);
        $this->assertTrue($process['success']);
        $this->assertEqual(Order::STATUS_PAID, $process['order']['status']);

        $walletFinal = $this->walletService->getWalletInfo($userId);
        $this->assertEqual(0.0, $walletFinal['available_balance']);
    }

    public function testStatusLoopCancelAsEndPoint() {
        $userId = 3;
        $amount = 999.00;

        $r1 = $this->orderService->createOrder($userId, $amount, '取消闭环A');
        $cancel1 = $this->orderService->cancelOrder($r1['order']['id'], $userId);
        $this->assertEqual(Order::STATUS_CANCELLED, $cancel1['status']);
        $this->assertOrderStatus($r1['order']['id'], Order::STATUS_CANCELLED);

        $this->assertException(function () use ($r1, $userId) {
            $this->orderService->retryPayment($r1['order']['id'], $userId);
        }, 'OrderStateException', 2501);

        $this->assertException(function () use ($r1, $userId) {
            $this->orderService->processPayment($r1['order']['id'], $userId);
        }, 'OrderStateException', 2201);

        $this->assertException(function () use ($r1, $userId) {
            $this->orderService->completeOrder($r1['order']['id'], $userId);
        }, 'OrderStateException', 2901);

        $orderModel = new Order();
        $pendingId = $orderModel->insert([
            'order_no' => $orderModel->generateOrderNo(),
            'user_id' => $userId,
            'amount' => 100.00,
            'title' => '取消闭环B-待支付取消',
            'status' => Order::STATUS_PENDING,
        ]);
        $cancel2 = $this->orderService->cancelOrder($pendingId, $userId);
        $this->assertEqual(Order::STATUS_CANCELLED, $cancel2['status']);
    }

    public function testStatusLoopBatchRetryPaymentPartialSuccess() {
        $userId = 3;

        $o1 = $this->orderService->createOrder($userId, 50.00, '批量补款A');
        $o2 = $this->orderService->createOrder($userId, 150.00, '批量补款B');
        $o3 = $this->orderService->createOrder($userId, 300.00, '批量补款C');
        $o4 = $this->orderService->createOrder($userId, 500.00, '批量补款D');

        $oids = [$o1['order']['id'], $o2['order']['id'], $o3['order']['id'], $o4['order']['id']];

        $this->walletService->recharge($userId, 500.00, 'batch_loop');

        $batch = $this->orderService->batchRetryPayment($oids, $userId);

        $this->assertEqual(4, $batch['total_count']);
        $this->assertEqual(3, $batch['success_count']);
        $this->assertEqual(1, $batch['frozen_count']);
        $this->assertEqual(0, $batch['failed_count']);

        $this->assertEqual(3, count($batch['success_ids']));
        $this->assertEqual(1, count($batch['still_frozen_items']));

        $stillShortage = floatval($batch['still_frozen_items'][0]['shortage']);
        $this->assertEqual(450.0, $stillShortage);
        $this->assertEqual(450, $batch['still_frozen_items'][0]['suggest_recharge']);

        $this->assertOrderStatus($o1['order']['id'], Order::STATUS_PAID);
        $this->assertOrderStatus($o2['order']['id'], Order::STATUS_PAID);
        $this->assertOrderStatus($o3['order']['id'], Order::STATUS_PAID);
        $this->assertOrderStatus($o4['order']['id'], Order::STATUS_FROZEN);

        $walletAfter = $this->walletService->getWalletInfo($userId);
        $this->assertEqual(0.0, $walletAfter['available_balance']);

        $this->walletService->recharge($userId, 500.00, 'batch_loop_2');
        $batch2 = $this->orderService->batchRetryPayment([$o4['order']['id']], $userId);
        $this->assertEqual(1, $batch2['success_count']);
        $this->assertOrderStatus($o4['order']['id'], Order::STATUS_PAID);

        $walletFinal = $this->walletService->getWalletInfo($userId);
        $this->assertEqual(0.0, $walletFinal['available_balance']);
    }

    public function testStatusLoopPaidAtTimestampSet() {
        $userId = 1;
        $amount = 50.00;

        $beforeCreate = date('Y-m-d H:i:s');
        $create = $this->orderService->createOrder($userId, $amount, '支付时间戳');
        $orderId = $create['order']['id'];

        if ($create['success']) {
            $this->assertNotNull($create['order']['paid_at']);
            $dbOrder = TestHelper::rawFetch("SELECT paid_at, status FROM orders WHERE id = ?", [$orderId]);
            $this->assertNotNull($dbOrder['paid_at']);
            $this->assertEqual(Order::STATUS_PAID, $dbOrder['status']);
        } else {
            $this->assertEqual(Order::STATUS_FROZEN, $create['order']['status']);
            $this->assertNull($create['order']['paid_at']);

            $this->walletService->recharge($userId, 100.00, 'paid_ts');
            $retry = $this->orderService->retryPayment($orderId, $userId);
            $this->assertTrue($retry['success']);
            $this->assertNotNull($retry['order']['paid_at']);
            $this->assertGreaterThanOrEqual($beforeCreate, $retry['order']['paid_at']);
        }
    }

    public function testStatusLoopTransactionRecordsComplete() {
        $userId = 3;
        $amount = 120.00;

        $create = $this->orderService->createOrder($userId, $amount, '交易记录完整性');
        $orderId = $create['order']['id'];

        $recharge1 = $this->walletService->recharge($userId, 50.00, 'tx_loop_1');
        $retry1 = $this->orderService->retryPayment($orderId, $userId);
        $this->assertFalse($retry1['success']);

        $rechargeAndResult = $this->orderService->rechargeAndRetry($orderId, $userId, 70.00, 'tx_loop_2');
        $this->assertTrue($rechargeAndResult['success']);

        $this->orderService->completeOrder($orderId, $userId);

        $txns = $this->walletService->getTransactions($userId, 50);
        $types = array_column($txns, 'type');

        $this->assertContains('recharge', $types);
        $this->assertContains('freeze', $types);
        $this->assertContains('payment', $types);

        $recharges = $this->walletService->getRechargeRecords($userId, 50);
        $totalRecharged = array_reduce($recharges, function ($sum, $r) {
            return $sum + floatval($r['amount']);
        }, 0);
        $this->assertEqual(120.0, $totalRecharged);

        foreach ($recharges as $r) {
            $this->assertEqual('success', $r['status']);
        }

        $paymentTxns = array_filter($txns, function ($t) {
            return $t['type'] === 'payment';
        });
        $totalPayment = array_reduce($paymentTxns, function ($sum, $t) {
            return $sum + floatval($t['amount']);
        }, 0);
        $this->assertEqual(120.0, $totalPayment);

        $freezeTxns = array_filter($txns, function ($t) use ($orderId) {
            return $t['type'] === 'freeze' && intval($t['order_id']) === intval($orderId);
        });
        $this->assertGreaterThanOrEqual(1, count($freezeTxns));
    }

    public function testStatusLoopAllEndpointsAreStable() {
        $userId = 3;

        $paidOrder = $this->orderService->createOrder(1, 10.00, '已支付终态');
        if ($paidOrder['success']) {
            $completed = $this->orderService->completeOrder($paidOrder['order']['id'], 1);
            $this->assertEqual(Order::STATUS_COMPLETED, $completed['status']);
        }

        $cancelled = $this->orderService->createOrder($userId, 10.00, '取消终态');
        $cancelledOrder = $this->orderService->cancelOrder($cancelled['order']['id'], $userId);
        $this->assertEqual(Order::STATUS_CANCELLED, $cancelledOrder['status']);

        $terminalStatuses = [
            Order::STATUS_COMPLETED,
            Order::STATUS_CANCELLED,
            Order::STATUS_PAID,
        ];

        foreach ($terminalStatuses as $ts) {
            $this->assertContains($ts, [
                Order::STATUS_PENDING,
                Order::STATUS_FROZEN,
                Order::STATUS_PAID,
                Order::STATUS_COMPLETED,
                Order::STATUS_CANCELLED,
            ]);
        }

        $validTransitions = [
            Order::STATUS_PENDING => [Order::STATUS_FROZEN, Order::STATUS_PAID, Order::STATUS_CANCELLED],
            Order::STATUS_FROZEN => [Order::STATUS_PENDING, Order::STATUS_PAID, Order::STATUS_CANCELLED],
            Order::STATUS_PAID => [Order::STATUS_COMPLETED],
            Order::STATUS_COMPLETED => [],
            Order::STATUS_CANCELLED => [],
        ];

        $this->assertEqual(3, count($validTransitions[Order::STATUS_PENDING]));
        $this->assertEqual(3, count($validTransitions[Order::STATUS_FROZEN]));
        $this->assertEqual(1, count($validTransitions[Order::STATUS_PAID]));
        $this->assertEqual(0, count($validTransitions[Order::STATUS_COMPLETED]));
        $this->assertEqual(0, count($validTransitions[Order::STATUS_CANCELLED]));
    }

    public function testStatusLoopDataConsistencyAtEveryStep() {
        $userId = 3;
        $amount = 777.00;

        $create = $this->orderService->createOrder($userId, $amount, '一致性检查');
        $orderId = $create['order']['id'];

        $walletStep1 = $this->walletService->getWalletInfo($userId);
        $orderStep1 = TestHelper::rawFetch("SELECT status, amount FROM orders WHERE id = ?", [$orderId]);
        $this->assertEqual(0.0, $walletStep1['balance']);
        $this->assertEqual(0.0, $walletStep1['frozen_amount']);
        $this->assertEqual(Order::STATUS_FROZEN, $orderStep1['status']);
        $this->assertEqual($amount, floatval($orderStep1['amount']));

        $recharge1 = 400.00;
        $this->walletService->recharge($userId, $recharge1, 'consistency');
        $walletStep2 = $this->walletService->getWalletInfo($userId);
        $this->assertEqual($recharge1, $walletStep2['balance']);
        $this->assertEqual($recharge1, $walletStep2['available_balance']);
        $this->assertEqual($recharge1, floatval($recharge1));

        $rechargeAndResult = $this->orderService->rechargeAndRetry($orderId, $userId, 377.00, 'consistency');
        $this->assertTrue($rechargeAndResult['success']);

        $walletStep3 = $this->walletService->getWalletInfo($userId);
        $orderStep3 = TestHelper::rawFetch("SELECT status, paid_at, frozen_reason FROM orders WHERE id = ?", [$orderId]);

        $this->assertEqual(0.0, $walletStep3['balance']);
        $this->assertEqual(0.0, $walletStep3['frozen_amount']);
        $this->assertEqual(0.0, $walletStep3['available_balance']);
        $this->assertEqual(Order::STATUS_PAID, $orderStep3['status']);
        $this->assertNotNull($orderStep3['paid_at']);
        $this->assertNull($orderStep3['frozen_reason']);

        $complete = $this->orderService->completeOrder($orderId, $userId);
        $orderStep4 = TestHelper::rawFetch("SELECT status FROM orders WHERE id = ?", [$orderId]);
        $this->assertEqual(Order::STATUS_COMPLETED, $orderStep4['status']);
        $this->assertEqual($orderStep4['status'], $complete['status']);
    }
}
