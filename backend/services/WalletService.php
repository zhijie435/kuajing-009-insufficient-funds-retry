<?php

require_once __DIR__ . '/../models/Wallet.php';
require_once __DIR__ . '/../models/WalletTransaction.php';
require_once __DIR__ . '/../models/RechargeRecord.php';

class WalletService {
    private $walletModel;
    private $transactionModel;
    private $rechargeModel;

    public function __construct() {
        $this->walletModel = new Wallet();
        $this->transactionModel = new WalletTransaction();
        $this->rechargeModel = new RechargeRecord();
    }

    private function beginTransaction() {
        return $this->walletModel->beginTransaction();
    }

    private function commit() {
        return $this->walletModel->commit();
    }

    private function rollBack() {
        return $this->walletModel->rollBack();
    }

    public function getWalletInfo($userId) {
        $wallet = $this->walletModel->getByUserId($userId);
        if (!$wallet) {
            return null;
        }
        return [
            'balance' => floatval($wallet['balance']),
            'frozen_amount' => floatval($wallet['frozen_amount']),
            'available_balance' => floatval($wallet['balance']) - floatval($wallet['frozen_amount']),
            'updated_at' => $wallet['updated_at'],
        ];
    }

    public function recharge($userId, $amount, $channel = 'manual') {
        if ($amount <= 0) {
            throw new Exception('充值金额必须大于0');
        }

        $this->beginTransaction();

        try {
            $wallet = $this->walletModel->getByUserId($userId);
            $balanceBefore = floatval($wallet['balance']);
            $balanceAfter = $balanceBefore + $amount;

            $this->walletModel->update($wallet['id'], [
                'balance' => $balanceAfter,
            ]);

            $this->transactionModel->insert([
                'user_id' => $userId,
                'type' => WalletTransaction::TYPE_RECHARGE,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => '账户充值',
            ]);

            $rechargeId = $this->rechargeModel->insert([
                'user_id' => $userId,
                'amount' => $amount,
                'channel' => $channel,
                'status' => RechargeRecord::STATUS_SUCCESS,
                'transaction_id' => 'TXN' . date('YmdHis') . mt_rand(1000, 9999),
            ]);

            $this->commit();

            return [
                'recharge_id' => $rechargeId,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ];
        } catch (Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function freeze($userId, $amount, $orderId = null, $description = '订单冻结') {
        if ($amount <= 0) {
            throw new Exception('冻结金额必须大于0');
        }

        $wallet = $this->walletModel->getByUserId($userId);
        $available = floatval($wallet['balance']) - floatval($wallet['frozen_amount']);

        if ($available < $amount) {
            throw new Exception('可用余额不足，无法冻结');
        }

        $this->beginTransaction();

        try {
            $newFrozenAmount = floatval($wallet['frozen_amount']) + $amount;

            $this->walletModel->update($wallet['id'], [
                'frozen_amount' => $newFrozenAmount,
            ]);

            $this->transactionModel->insert([
                'user_id' => $userId,
                'order_id' => $orderId,
                'type' => WalletTransaction::TYPE_FREEZE,
                'amount' => $amount,
                'balance_before' => floatval($wallet['balance']),
                'balance_after' => floatval($wallet['balance']),
                'description' => $description,
            ]);

            $this->commit();

            return true;
        } catch (Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function unfreeze($userId, $amount, $orderId = null, $description = '订单解冻') {
        if ($amount <= 0) {
            throw new Exception('解冻金额必须大于0');
        }

        $wallet = $this->walletModel->getByUserId($userId);

        if (floatval($wallet['frozen_amount']) < $amount) {
            throw new Exception('冻结金额不足，无法解冻');
        }

        $this->beginTransaction();

        try {
            $newFrozenAmount = floatval($wallet['frozen_amount']) - $amount;

            $this->walletModel->update($wallet['id'], [
                'frozen_amount' => $newFrozenAmount,
            ]);

            $this->transactionModel->insert([
                'user_id' => $userId,
                'order_id' => $orderId,
                'type' => WalletTransaction::TYPE_UNFREEZE,
                'amount' => $amount,
                'balance_before' => floatval($wallet['balance']),
                'balance_after' => floatval($wallet['balance']),
                'description' => $description,
            ]);

            $this->commit();

            return true;
        } catch (Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function deduct($userId, $amount, $orderId = null, $description = '订单支付') {
        if ($amount <= 0) {
            throw new Exception('扣款金额必须大于0');
        }

        $wallet = $this->walletModel->getByUserId($userId);
        $available = floatval($wallet['balance']) - floatval($wallet['frozen_amount']);

        if ($available < $amount) {
            throw new Exception('可用余额不足');
        }

        $this->beginTransaction();

        try {
            $balanceBefore = floatval($wallet['balance']);
            $balanceAfter = $balanceBefore - $amount;

            $this->walletModel->update($wallet['id'], [
                'balance' => $balanceAfter,
            ]);

            $this->transactionModel->insert([
                'user_id' => $userId,
                'order_id' => $orderId,
                'type' => WalletTransaction::TYPE_PAYMENT,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
            ]);

            $this->commit();

            return [
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ];
        } catch (Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function deductFromFrozen($userId, $amount, $orderId = null, $description = '冻结金额扣款') {
        if ($amount <= 0) {
            throw new Exception('扣款金额必须大于0');
        }

        $wallet = $this->walletModel->getByUserId($userId);

        if (floatval($wallet['frozen_amount']) < $amount) {
            throw new Exception('冻结金额不足');
        }

        $this->beginTransaction();

        try {
            $balanceBefore = floatval($wallet['balance']);
            $frozenBefore = floatval($wallet['frozen_amount']);
            $balanceAfter = $balanceBefore - $amount;
            $frozenAfter = $frozenBefore - $amount;

            $this->walletModel->update($wallet['id'], [
                'balance' => $balanceAfter,
                'frozen_amount' => $frozenAfter,
            ]);

            $this->transactionModel->insert([
                'user_id' => $userId,
                'order_id' => $orderId,
                'type' => WalletTransaction::TYPE_PAYMENT,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
            ]);

            $this->commit();

            return [
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ];
        } catch (Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function getTransactions($userId, $limit = 20) {
        return $this->transactionModel->getListByUserId($userId, $limit);
    }

    public function getRechargeRecords($userId, $limit = 20) {
        return $this->rechargeModel->getListByUserId($userId, $limit);
    }
}
