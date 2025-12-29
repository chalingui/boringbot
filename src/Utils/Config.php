<?php
declare(strict_types=1);

namespace BoringBot\Utils;

final class Config
{
    public static function load(string $rootDir): array
    {
        self::loadDotEnv($rootDir);
        self::applyTimezone();

        $configFile = $rootDir . '/config/config.php';
        /** @var array $cfg */
        $cfg = is_file($configFile) ? require $configFile : [
            'db_path' => $rootDir . '/db/boringbot.sqlite',
            'log_path' => $rootDir . '/logs/boringbot.log',
            'lock_path' => $rootDir . '/storage/boringbot.lock',
            'bybit' => [
                'base_url' => getenv('BYBIT_BASE_URL') ?: 'https://api.bybit.com',
                'api_key' => getenv('BYBIT_API_KEY') ?: '',
                'api_secret' => getenv('BYBIT_API_SECRET') ?: '',
                'recv_window' => (int)(getenv('BYBIT_RECV_WINDOW') ?: 5000),
            ],
            'symbols' => [
                'trade' => getenv('SYMBOL_TRADE') ?: 'ETHUSDT',
                'profit_convert' => getenv('SYMBOL_PROFIT_CONVERT') ?: 'USDCUSDT',
            ],
            'strategy' => [
                'dca_amount_usdt' => (float)(getenv('DCA_AMOUNT_USDT') ?: 100),
                'dca_interval_days' => (int)(getenv('DCA_INTERVAL_DAYS') ?: 7),
                'sell_markup_pct' => (float)(getenv('SELL_MARKUP_PCT') ?: 5.0),
            ],
        ];

        // Optional overrides (useful for local testing without touching production DB/logs/lock).
        $dbPath = getenv('BORINGBOT_DB_PATH');
        if (is_string($dbPath) && $dbPath !== '') {
            $cfg['db_path'] = $dbPath;
        }
        $logPath = getenv('BORINGBOT_LOG_PATH');
        if (is_string($logPath) && $logPath !== '') {
            $cfg['log_path'] = $logPath;
        }
        $lockPath = getenv('BORINGBOT_LOCK_PATH');
        if (is_string($lockPath) && $lockPath !== '') {
            $cfg['lock_path'] = $lockPath;
        }

        return $cfg;
    }

    private static function applyTimezone(): void
    {
        $tz = getenv('BORINGBOT_TIMEZONE') ?: (getenv('APP_TIMEZONE') ?: '');
        $tz = is_string($tz) ? trim($tz) : '';
        if ($tz === '') {
            return;
        }
        // Avoid warnings on invalid TZ; keep PHP default if invalid.
        try {
            new \DateTimeZone($tz);
        } catch (\Throwable) {
            return;
        }
        date_default_timezone_set($tz);
    }

    private static function loadDotEnv(string $rootDir): void
    {
        foreach ([$rootDir . '/.env', $rootDir . '/config/.env'] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
                $key = trim($key);
                $value = trim($value);
                if ($key === '' || getenv($key) !== false) {
                    continue;
                }
                $value = trim($value, "\"'");
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}
