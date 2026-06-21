<?php

require_once __DIR__ . '/BaseTestCase.php';

class BalanceShortageRetryTest extends BaseTestCase {

    public function testCreateOrderInsufficientBalanceShouldFreezeOrder() {
        $userId = 2;
        $walletInfo = $this->walletService->getWalletInfo($userId);
        $this->assertEqual(0.0, $walletInfo['available_balance']);

        $result = $this->orderService->createOrder($userId, 500.00, '测试订单-余额不足');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['frozen']);
        $this->assertEqual(Order::STATUS_FROZEN, $result['order']['status']);
        $this->assertEqual(500.0, floatval($result['shortage']));
        $this->assertEqual(500, $result['suggest_recharge']);
        $this->assertContains('余额不足', $result['message']);
    }

    public function testFreezeOrderThenRechargeThenRetrySuccess() {
        $userId = 3;
        $orderAmount = 300.00;

        $createResult = $this->orderService->createOrder($userId, $orderAmount, '先冻结后充值重试');
        $this->assertFalse($createResult['success']);
        $this->assertTrue($createResult['frozen']);
        $orderId = $createResult['order']['id'];

        $retryBefore = $this->orderService->retryPayment($orderId, $userId);
        $this->assertFalse($retryBefore['success']);
        $this->assertTrue($retryBefore['frozen']);
        $this->assertEqual(300.0, floatval($retryBefore['shortage']));

        $this->walletService->recharge($userId, 500.00, 'test');
        $walletAfter = $this->walletService->getWalletInfo($userId);
        $this->assertEqual(500.0, $walletAfter['available_balance']);

        $retryResult = $this->orderService->retryPayment($orderId, $userId);
        $this->assertTrue($retryResult['success']);
        $this->assertFalse($retryResult['frozen']);
        $this->assertEqual(Order::STATUS_PAID, $retryResult['order']['status']);
        $this->assertEqual(0.0, floatval($retryResult['shortage']));
        $this->assertEqual(0, $retryResult['suggest_recharge']);
        $this->assertNotNull($retryResult['order']['paid_at']);

        $walletFinal = $this->walletService->getWalletInfo($userId);
        $this->assertEqual(200.0, $walletFinal['available_balance']);
        $this->assertEqual(200.0, $walletFinal['balance']);
        $this->assertEqual(0.0, $walletFinal['frozen_amount']);
    }

    public function testPartialRechargeStillShortageThenMoreRechargeThenSuccess() {
        $userId = 3;
        $orderAmount = 1000.00;

        $createResult = $this->orderService->createOrder($userId, $orderAmount, '分多次充值补款');
        $this->assertFalse($createResult['success']);
        $orderId = $createResult['order']['id'];
        $this->assertEqual(1000.0, floatval($createResult['shortage']));

        $this->walletService->recharge($userId, 300.00, 'test');
        $retry1 = $this->orderService->retryPayment($orderId, $userId);
        $this->assertFalse($retry1['success']);
        $this->assertTrue($retry1['frozen']);
        $this->assertEqual(700.0, floatval($retry1['shortage']));
        $this->assertEqual(700, $retry1['suggest_recharge']);
        $this->assertContains('仍然不足', $retry1['message']);

        $this->walletService->recharge($userId, 500.00, 'test');
        $retry2 = $this->orderService->retryPayment($orderId, $userId);
        $this->assertFalse($retry2['success']);
        $this->assertEqual(200.0, floatval($retry2['shortage']));

        $this->walletService->recharge($userId, 200.00, 'test');
        $retry3 = $this->orderService->retryPayment($orderId, $userId);
        $this->assertTrue($retry3['success']);
        $this->assertEqual(Order::STATUS_PAID, $retry3['order']['status']);

        $walletFinal = $this->walletService->getWalletInfo($userId);
        $this->assertEqual(0.0, $walletFinal['available_balance']);
    }

    public function testRechargeAndRetryOneStepWithSufficientAmount() {
        $userId = 3;
        $orderAmount = 500.00;

        $createResult = $this->orderService->createOrder($userId, $orderAmount, '一键充值补款');
        $this->assertFalse($createResult['success']);
        $orderId = $createResult['order']['id'];

        $result = $this->orderService->rechargeAndRetry($orderId, $userId, 600.00, 'test');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['frozen']);
        $this->assertEqual(Order::STATUS_PAID, $result['order']['status']);
        $this->assertNotNull($result['recharge']);
        $this->assertEqual(600.0, floatval($result['recharge']['amount']));
        $this->assertEqual(0.0, floatval($result['shortage']));

        $walletFinal = $this->walletService->getWalletInfo($userId);
        $this->assertEqual(100.0, $walletFinal['available_balance']);

        $recharges = $this->walletService->getRechargeRecords($userId);
        $this->assertGreaterThanOrEqual(1, count($recharges));
        $this->assertEqual('success', $recharges[0]['status']);
    }

    public function testRechargeAndRetryOneStepWithInsufficientAmount() {
        $userId = 3;
        $orderAmount = 1000.00;

        $createResult = $this->orderService->createOrder($userId, $orderAmount, '一键充值补款-金额不足');
        $this->assertFalse($createResult['success']);
        $orderId = $createResult['order']['id'];

        $result = $this->orderService->rechargeAndRetry($orderId, $userId, 300.00, 'test');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['frozen']);
        $this->assertEqual(Order::STATUS_FROZEN, $result['order']['status']);
        $this->assertNotNull($result['recharge']);
        $this->assertEqual(300.0, floatval($result['recharge']['amount']));
        $this->assertEqual(700.0, floatval($result['shortage']));
        $this->assertEqual(700, $result['suggest_recharge']);
        $this->assertContains('仍不足', $result['message']);

        $walletAfter = $this->walletService->getWalletInfo($userId);
        $this->assertEqual(300.0, $walletAfter['balance']);
        $this->assertEqual(300.0, $walletAfter['available_balance']);

        $result2 = $this->orderService->rechargeAndRetry($orderId, $userId, 700.00, 'test');
        $this->assertTrue($result2['success']);
        $this->assertEqual(Order::STATUS_PAID, $result2['order']['status']);
    }

    public function testProcessPaymentOnFrozenOrderAfterBalanceChange() {
        $userId = 3;
        $orderAmount = 200.00;

        $createResult = $this->orderService->createOrder($userId, $orderAmount, 'processPayment重试');
        $orderId = $createResult['order']['id'];
        $this->assertEqual(Order::STATUS_FROZEN, $createResult['order']['status']);

        $process1 = $this->orderService->processPayment($orderId, $userId);
        $this->assertFalse($process1['success']);
        $this->assertTrue($process1['frozen']);

        $this->walletService->recharge($userId, 200.00, 'test');
        $process2 = $this->orderService->processPayment($orderId, $userId);
        $this->assertTrue($process2['success']);
        $this->assertEqual(Order::STATUS_PAID, $process2['order']['status']);
    }

    public function testRetryPaymentNonFrozenOrderShouldThrowException() {
        $userId = 1;
        $createResult = $this->orderService->createOrder($userId, 50.00, '待支付订单不能直接retry');
        $orderId = $createResult['order']['id'];
        if ($createResult['success']) {
            $this->assertEqual(Order::STATUS_PAID, $createResult['order']['status']);
        } else {
            $this->assertEqual(Order::STATUS_FROZEN, $createResult['order']['status']);
        }

        $order = TestHelper::rawFetch("SELECT * FROM orders WHERE id = ?", [$orderId]);
        if ($order['status'] !== Order::STATUS_FROZEN) {
            $this->assertException(function () use ($orderId, $userId) {
                $this->orderService->retryPayment($orderId, $userId);
            }, 'OrderStateException', 2501);
        }
    }

    public function testMultipleFrozenOrdersSequentialRetry() {
        $userId = 3;
        $amounts = [100.00, 200.00, 300.00];
        $orderIds = [];

        foreach ($amounts as $amt) {
            $r = $this->orderService->createOrder($userId, $amt, "批量订单{$amt}");
            $this->assertFalse($r['success']);
            $orderIds[] = $r['order']['id'];
        }

        $frozenOrders = $this->orderService->getFrozenOrders($userId);
        $this->assertEqual(3, count($frozenOrders));

        $this->walletService->recharge($userId, 600.00, 'test');

        foreach ($orderIds as $oid) {
            $r = $this->orderService->retryPayment($oid, $userId);
            $this->assertTrue($r['success']);
            $this->assertEqual(Order::STATUS_PAID, $r['order']['status']);
        }

        $walletFinal = $this->walletService->getWalletInfo($userId);
        $this->assertEqual(0.0, $walletFinal['available_balance']);
    }

    public function testGetOrderDetailShowsCorrectShortageAndActions() {
        $userId = 3;
        $createResult = $this->orderService->createOrder($userId, 888.00, '订单详情测试');
        $orderId = $createResult['order']['id'];

        $detail = $this->orderService->getOrderDetail($orderId, $userId);
        $this->assertEqual(888.0, floatval($detail['shortage']));
        $this->assertEqual(888, $detail['suggest_recharge']);
        $this->assertTrue($detail['can_retry']);
        $this->assertTrue($detail['can_pay']);
        $this->assertTrue($detail['can_cancel']);
        $this->assertFalse($detail['can_complete']);
        $this->assertFalse($detail['has_enough_balance']);

        $this->walletService->recharge($userId, 888.00, 'test');

        $detail2 = $this->orderService->getOrderDetail($orderId, $userId);
        $this->assertEqual(0.0, floatval($detail2['shortage']));
        $this->assertTrue($detail2['has_enough_balance']);
    }

    public function testFrozenSummaryAggregation() {
        $userId = 3;
        $this->orderService->createOrder($userId, 100.00, '汇总订单A');
        $this->orderService->createOrder($userId, 200.50, '汇总订单B');
        $this->orderService->createOrder($userId, 99.99, '汇总订单C');

        $summary = $this->orderService->getFrozenSummary($userId);
        $this->assertEqual(3, $summary['total_count']);
        $this->assertEqual(400.49, floatval($summary['total_amount']));
        $this->assertEqual(0, $summary['payable_count']);
        $this->assertEqual(3, $summary['need_recharge_count']);
        $this->assertEqual(400.49, floatval($summary['shortage']));
        $this->assertEqual(401, $summary['suggest_recharge']);

        $this->walletService->recharge($userId, 150.00, 'test');
        $summary2 = $this->orderService->getFrozenSummary($userId);
        $this->assertEqual(1, $summary2['payable_count']);
        $this->assertEqual(2, $summary2['need_recharge_count']);
        $this->assertEqual(250.49, floatval($summary2['shortage']));
    }
}
