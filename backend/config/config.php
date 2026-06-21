<?php

return [
    'db' => [
        'type' => getenv('DB_TYPE') ?: 'sqlite',
        'path' => getenv('DB_PATH') ?: __DIR__ . '/../database/crm.db',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'crm',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
    ],
    'user' => [
        'default_user_id' => intval(getenv('DEFAULT_USER_ID') ?: 1),
    ],
    'order' => [
        'freeze' => [
            'enabled' => getenv('ORDER_FREEZE_ENABLED') !== 'false',
            'default_reason' => getenv('ORDER_FREEZE_DEFAULT_REASON') ?: '余额不足，订单已冻结，请充值后重试',
            'auto_unfreeze' => getenv('ORDER_AUTO_UNFREEZE') === 'true',
            'expire_hours' => intval(getenv('ORDER_FREEZE_EXPIRE_HOURS') ?: 72),
        ],
        'retry' => [
            'max_attempts' => intval(getenv('ORDER_RETRY_MAX_ATTEMPTS') ?: 0),
            'auto_retry_on_recharge' => getenv('AUTO_RETRY_ON_RECHARGE') === 'true',
        ],
        'batch' => [
            'max_batch_size' => intval(getenv('ORDER_BATCH_MAX_SIZE') ?: 100),
        ],
    ],
    'wallet' => [
        'recharge' => [
            'min_amount' => floatval(getenv('RECHARGE_MIN_AMOUNT') ?: 0.01),
            'max_amount' => floatval(getenv('RECHARGE_MAX_AMOUNT') ?: 1000000),
            'default_channel' => getenv('RECHARGE_DEFAULT_CHANNEL') ?: 'manual',
        ],
        'freeze' => [
            'timeout_seconds' => intval(getenv('WALLET_FREEZE_TIMEOUT') ?: 1800),
        ],
        'supplement' => [
            'enabled' => getenv('SUPPLEMENT_PAYMENT_ENABLED') !== 'false',
            'auto_retry_frozen_orders' => getenv('AUTO_RETRY_FROZEN_ORDERS') === 'true',
            'retry_order_by_amount_asc' => getenv('RETRY_ORDER_BY_AMOUNT_ASC') !== 'false',
        ],
    ],
    'api' => [
        'cors_enabled' => getenv('API_CORS_ENABLED') !== 'false',
        'cors_origin' => getenv('API_CORS_ORIGIN') ?: '*',
        'debug' => getenv('API_DEBUG') === 'true',
    ],
];
