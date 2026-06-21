<?php

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../models/BaseModel.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Wallet.php';
require_once __DIR__ . '/../models/WalletTransaction.php';
require_once __DIR__ . '/../models/RechargeRecord.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../services/WalletService.php';
require_once __DIR__ . '/../services/OrderService.php';

class TestHelper {
    public static function createTestPdo() {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::initTables($pdo);
        self::seedData($pdo);
        return $pdo;
    }

    private static function initTables($pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(50) NOT NULL UNIQUE,
            nickname VARCHAR(100) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS wallets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL UNIQUE,
            balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            frozen_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_no VARCHAR(32) NOT NULL UNIQUE,
            user_id INTEGER NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            frozen_reason TEXT,
            paid_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            order_id INTEGER,
            type VARCHAR(20) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            balance_before DECIMAL(10,2) NOT NULL,
            balance_after DECIMAL(10,2) NOT NULL,
            description VARCHAR(200) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (order_id) REFERENCES orders(id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS recharge_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            channel VARCHAR(20) NOT NULL DEFAULT 'manual',
            transaction_id VARCHAR(100),
            status VARCHAR(20) NOT NULL DEFAULT 'success',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )");
    }

    private static function seedData($pdo) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
        $result = $stmt->fetch();

        if ($result['count'] == 0) {
            $pdo->exec("INSERT INTO users (id, username, nickname) VALUES (1, 'demo', '演示用户')");
            $pdo->exec("INSERT INTO wallets (user_id, balance, frozen_amount) VALUES (1, 100.00, 0.00)");

            $pdo->exec("INSERT INTO users (id, username, nickname) VALUES (2, 'testuser', '测试用户')");
            $pdo->exec("INSERT INTO wallets (user_id, balance, frozen_amount) VALUES (2, 0.00, 0.00)");

            $pdo->exec("INSERT INTO users (id, username, nickname) VALUES (3, 'poorbuyer', '穷买家')");
            $pdo->exec("INSERT INTO wallets (user_id, balance, frozen_amount) VALUES (3, 0.00, 0.00)");
        }
    }

    public static function rawQuery($sql, $params = []) {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function rawFetch($sql, $params = []) {
        $stmt = self::rawQuery($sql, $params);
        return $stmt->fetch();
    }

    public static function rawFetchAll($sql, $params = []) {
        $stmt = self::rawQuery($sql, $params);
        return $stmt->fetchAll();
    }
}

class BaseTestCase {
    protected $passed = 0;
    protected $failed = 0;
    protected $errors = [];

    protected $walletService;
    protected $orderService;

    public function setUp() {
        $pdo = TestHelper::createTestPdo();
        Database::setTestPdo($pdo);

        $this->resetTransactionLevel();

        $this->walletService = new WalletService();
        $this->orderService = new OrderService();
    }

    private function resetTransactionLevel() {
        $reflection = new ReflectionClass('BaseModel');
        $prop = $reflection->getProperty('transactionLevel');
        $prop->setAccessible(true);
        $prop->setValue(null, 0);
    }

    public function tearDown() {
        Database::clearTestPdo();
    }

    public function run() {
        $this->setUp();
        $methods = get_class_methods($this);
        foreach ($methods as $method) {
            if (strpos($method, 'test') === 0) {
                try {
                    $this->$method();
                    $this->passed++;
                    echo "  ✓ {$method}\n";
                } catch (Exception $e) {
                    $this->failed++;
                    $this->errors[] = [
                        'method' => $method,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ];
                    echo "  ✗ {$method}\n    Error: {$e->getMessage()}\n    File: {$e->getFile()}:{$e->getLine()}\n";
                }
            }
        }
        $this->tearDown();
        return [$this->passed, $this->failed, $this->errors];
    }

    protected function assertEqual($expected, $actual, $message = '') {
        if ($expected !== $actual) {
            $msg = $message ?: "Expected " . var_export($expected, true) . " but got " . var_export($actual, true);
            throw new Exception($msg);
        }
    }

    protected function assertNotEqual($expected, $actual, $message = '') {
        if ($expected === $actual) {
            $msg = $message ?: "Expected not equal to " . var_export($expected, true);
            throw new Exception($msg);
        }
    }

    protected function assertTrue($condition, $message = '') {
        if ($condition !== true) {
            $msg = $message ?: "Expected true but got " . var_export($condition, true);
            throw new Exception($msg);
        }
    }

    protected function assertFalse($condition, $message = '') {
        if ($condition !== false) {
            $msg = $message ?: "Expected false but got " . var_export($condition, true);
            throw new Exception($msg);
        }
    }

    protected function assertNull($value, $message = '') {
        if ($value !== null) {
            $msg = $message ?: "Expected null but got " . var_export($value, true);
            throw new Exception($msg);
        }
    }

    protected function assertNotNull($value, $message = '') {
        if ($value === null) {
            $msg = $message ?: "Expected not null";
            throw new Exception($msg);
        }
    }

    protected function assertEmpty($value, $message = '') {
        if (!empty($value)) {
            $msg = $message ?: "Expected empty but got " . var_export($value, true);
            throw new Exception($msg);
        }
    }

    protected function assertNotEmpty($value, $message = '') {
        if (empty($value)) {
            $msg = $message ?: "Expected not empty";
            throw new Exception($msg);
        }
    }

    protected function assertException($callback, $expectedException = null, $expectedCode = null, $message = '') {
        $caught = null;
        try {
            $callback();
        } catch (Exception $e) {
            $caught = $e;
        }

        if ($caught === null) {
            $msg = $message ?: "Expected exception was not thrown";
            throw new Exception($msg);
        }

        if ($expectedException !== null && !($caught instanceof $expectedException)) {
            $msg = $message ?: "Expected exception {$expectedException} but got " . get_class($caught);
            throw new Exception($msg);
        }

        if ($expectedCode !== null && $caught->getCode() !== $expectedCode) {
            $msg = $message ?: "Expected exception code {$expectedCode} but got {$caught->getCode()}";
            throw new Exception($msg);
        }
    }

    protected function assertArrayHasKey($key, $array, $message = '') {
        if (!is_array($array) || !array_key_exists($key, $array)) {
            $msg = $message ?: "Expected array to have key '{$key}'";
            throw new Exception($msg);
        }
    }

    protected function assertBetween($min, $max, $actual, $message = '') {
        if ($actual < $min || $actual > $max) {
            $msg = $message ?: "Expected {$actual} to be between {$min} and {$max}";
            throw new Exception($msg);
        }
    }

    protected function assertGreaterThan($expected, $actual, $message = '') {
        if ($actual <= $expected) {
            $msg = $message ?: "Expected {$actual} to be greater than {$expected}";
            throw new Exception($msg);
        }
    }

    protected function assertLessThan($expected, $actual, $message = '') {
        if ($actual >= $expected) {
            $msg = $message ?: "Expected {$actual} to be less than {$expected}";
            throw new Exception($msg);
        }
    }

    protected function assertContains($needle, $haystack, $message = '') {
        if (is_array($haystack)) {
            if (!in_array($needle, $haystack)) {
                $msg = $message ?: "Expected array to contain " . var_export($needle, true);
                throw new Exception($msg);
            }
        } elseif (is_string($haystack)) {
            if (strpos($haystack, $needle) === false) {
                $msg = $message ?: "Expected string to contain '{$needle}'";
                throw new Exception($msg);
            }
        } else {
            throw new Exception("assertContains requires array or string haystack");
        }
    }
}

class TestRunner {
    public static function run($testClasses) {
        $totalPassed = 0;
        $totalFailed = 0;
        $allErrors = [];

        echo "\n========================================\n";
        echo "  CRM 客户跟进系统 - 单元测试\n";
        echo "========================================\n\n";

        foreach ($testClasses as $testClass) {
            if (!class_exists($testClass)) {
                require_once __DIR__ . '/' . $testClass . '.php';
            }
            $test = new $testClass();
            echo "【{$testClass}】\n";
            list($passed, $failed, $errors) = $test->run();
            $totalPassed += $passed;
            $totalFailed += $failed;
            $allErrors = array_merge($allErrors, $errors);
            echo "  结果: 通过 {$passed}, 失败 {$failed}\n\n";
        }

        echo "========================================\n";
        echo "  总计: 通过 {$totalPassed}, 失败 {$totalFailed}\n";
        echo "========================================\n";

        if ($totalFailed > 0) {
            echo "\n失败详情:\n";
            foreach ($allErrors as $idx => $err) {
                echo ($idx + 1) . ". {$err['method']}\n";
                echo "   错误: {$err['error']}\n";
                echo "   位置: {$err['file']}:{$err['line']}\n\n";
            }
            exit(1);
        } else {
            echo "\n所有测试通过! 🎉\n\n";
            exit(0);
        }
    }
}
