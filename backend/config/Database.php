<?php

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $config = require __DIR__ . '/../config/config.php';
        $dbPath = $config['db']['path'];
        
        if (!file_exists(dirname($dbPath))) {
            mkdir(dirname($dbPath), 0777, true);
        }
        
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        $this->initTables();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    private function initTables() {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(50) NOT NULL UNIQUE,
            nickname VARCHAR(100) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS wallets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL UNIQUE,
            balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            frozen_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS orders (
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

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
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

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS recharge_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            channel VARCHAR(20) NOT NULL DEFAULT 'manual',
            transaction_id VARCHAR(100),
            status VARCHAR(20) NOT NULL DEFAULT 'success',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )");

        $this->seedData();
    }

    private function seedData() {
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM users");
        $result = $stmt->fetch();
        
        if ($result['count'] == 0) {
            $this->pdo->exec("INSERT INTO users (username, nickname) VALUES ('demo', '演示用户')");
            
            $this->pdo->exec("INSERT INTO wallets (user_id, balance) VALUES (1, 100.00)");
        }
    }
}
