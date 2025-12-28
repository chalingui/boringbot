<?php
declare(strict_types=1);

return [
    'db_path' => __DIR__ . '/../db/boringbot.sqlite',
    'log_path' => __DIR__ . '/../logs/boringbot.log',
    'lock_path' => __DIR__ . '/../storage/boringbot.lock',

    'bybit' => [
        'base_url' => getenv('BYBIT_BASE_URL') ?: 'https://api.bybit.com',
        'api_key' => getenv('BYBIT_API_KEY') ?: '',
        'api_secret' => getenv('BYBIT_API_SECRET') ?: '',
        'recv_window' => (int)(getenv('BYBIT_RECV_WINDOW') ?: 5000),
        'account_type' => getenv('BYBIT_ACCOUNT_TYPE') ?: 'SPOT',
    ],

    'symbols' => [
        'trade' => getenv('SYMBOL_TRADE') ?: 'ETHUSDT',
        // Market conversion for profits: spend USDT, receive USDC.
        'profit_convert' => getenv('SYMBOL_PROFIT_CONVERT') ?: 'USDCUSDT',
    ],

    'strategy' => [
        'dca_amount_usdt' => (float)(getenv('DCA_AMOUNT_USDT') ?: 100),
        'dca_interval_days' => (int)(getenv('DCA_INTERVAL_DAYS') ?: 7),
        'sell_markup_pct' => (float)(getenv('SELL_MARKUP_PCT') ?: 5.0),
    ],

    'notify' => [
        'enabled' => (getenv('NOTIFY_ENABLED') ?: '0') === '1',
        'email_to' => getenv('NOTIFY_EMAIL_TO') ?: '',
        'email_from' => getenv('NOTIFY_EMAIL_FROM') ?: (getenv('SMTP_USER') ?: ''),
        'cooldown_minutes' => (int)(getenv('NOTIFY_COOLDOWN_MINUTES') ?: 720),
        'smtp' => [
            'host' => getenv('SMTP_HOST') ?: '',
            'port' => (int)(getenv('SMTP_PORT') ?: 587),
            'user' => getenv('SMTP_USER') ?: '',
            'pass' => getenv('SMTP_PASS') ?: '',
            'encryption' => getenv('SMTP_ENCRYPTION') ?: 'starttls',
        ],
    ],
];
