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
        'trade' => getenv('SYMBOL_TRADE_ETH') ?: (getenv('SYMBOL_TRADE') ?: 'ETHUSDT'),
        'trade_btc' => getenv('SYMBOL_TRADE_BTC') ?: 'BTCUSDT',
    ],

    'strategy' => [
        'buy_enabled' => (($v = getenv('BUY_ENABLED')) !== false && trim((string)$v) !== '')
            ? !in_array(strtolower(trim((string)$v)), ['0', 'false', 'off', 'no'], true)
            : true,
        'dca_amount_usdt' => (float)(getenv('DCA_AMOUNT_USDT') ?: 100),
        'dca_interval_days' => (int)(getenv('DCA_INTERVAL_DAYS') ?: 7),
        'dca_offset_hours' => (int)(getenv('DCA_OFFSET_HOURS') ?: 0),
        'sell_markup_pct' => (float)(getenv('SELL_MARKUP_PCT') ?: 5.0),
        'sell_qty_buffer' => (float)(getenv('SELL_QTY_BUFFER') ?: 0.0),
    ],

    'notify' => [
        'enabled' => (getenv('NOTIFY_ENABLED') ?: '0') === '1',
        'email_to' => getenv('NOTIFY_EMAIL_TO') ?: '',
        'email_from' => getenv('NOTIFY_EMAIL_FROM') ?: (getenv('SMTP_USER') ?: ''),
        'cooldown_minutes' => (int)(getenv('NOTIFY_COOLDOWN_MINUTES') ?: 60),
        // Sends a reminder when the next DCA is within this window and there are not enough funds.
        'no_funds_lead_hours' => (int)(getenv('NOTIFY_NO_FUNDS_LEAD_HOURS') ?: 24),
        'smtp' => [
            'host' => getenv('SMTP_HOST') ?: '',
            'port' => (int)(getenv('SMTP_PORT') ?: 587),
            'user' => getenv('SMTP_USER') ?: '',
            'pass' => getenv('SMTP_PASS') ?: '',
            'encryption' => getenv('SMTP_ENCRYPTION') ?: 'starttls',
        ],
    ],

    'transfers' => [
        'enabled' => (getenv('TRANSFER_ENABLED') ?: '0') === '1',
        'from_account' => getenv('TRANSFER_FROM_ACCOUNT') ?: (getenv('BYBIT_ACCOUNT_TYPE') ?: 'SPOT'),
        'principal_to_account' => getenv('TRANSFER_PRINCIPAL_TO_ACCOUNT') ?: '',
        'profit_to_account' => getenv('TRANSFER_PROFIT_TO_ACCOUNT') ?: '',
        'base_asset_enabled' => (getenv('TRANSFER_BASE_ASSET_ENABLED') ?: '0') === '1',
        'base_asset_from_account' => getenv('TRANSFER_BASE_ASSET_FROM_ACCOUNT') ?: 'FUND',
        'base_asset_to_account' => getenv('TRANSFER_BASE_ASSET_TO_ACCOUNT') ?: (getenv('BYBIT_ACCOUNT_TYPE') ?: 'SPOT'),
    ],
];
