<?php
declare(strict_types=1);

namespace BoringBot\Bot;

use BoringBot\DB\Database;
use BoringBot\Exchange\BybitClient;
use BoringBot\Utils\Logger;
use Throwable;

final class Reconciler
{
    public function __construct(
        private readonly Database $db,
        private readonly BybitClient $bybit,
        private readonly Logger $logger,
        private readonly bool $dryRun,
    ) {
    }

    public function reconcileUsdt(): void
    {
        $bybitUsdt = $this->bybit->walletBalance('USDT');
        if ($bybitUsdt === null) {
            throw new \RuntimeException('Could not fetch Bybit USDT balance.');
        }

        $bot = $this->getBalance('USDT');
        $delta = $bybitUsdt - $bot;

        $this->logger->info('Reconcile fetched Bybit balance', [
            'bybit_usdt' => number_format($bybitUsdt, 8, '.', ''),
            'bot_usdt' => number_format($bot, 8, '.', ''),
            'delta' => number_format(max(0.0, $delta), 8, '.', ''),
            'dry_run' => $this->dryRun,
        ]);

        if ($delta <= 0) {
            $this->insertEvent('RECONCILE', [
                'bybit_usdt' => number_format($bybitUsdt, 8, '.', ''),
                'bot_usdt' => number_format($bot, 8, '.', ''),
                'delta' => 0,
                'note' => 'No positive delta; no update.',
                'dry_run' => $this->dryRun,
            ]);
            return;
        }

        if ($this->dryRun) {
            $this->insertEvent('RECONCILE', [
                'bybit_usdt' => number_format($bybitUsdt, 8, '.', ''),
                'bot_usdt' => number_format($bot, 8, '.', ''),
                'delta' => number_format($delta, 8, '.', ''),
                'note' => 'Dry-run; would increase balances.USDT.',
                'dry_run' => true,
            ]);
            return;
        }

        try {
            $this->db->begin();
            $this->addBalance('USDT', $delta);
            $this->insertEvent('RECONCILE', [
                'bybit_usdt' => number_format($bybitUsdt, 8, '.', ''),
                'bot_usdt' => number_format($bot, 8, '.', ''),
                'delta' => number_format($delta, 8, '.', ''),
                'new_bot_usdt' => number_format($bot + $delta, 8, '.', ''),
                'dry_run' => false,
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertEvent(string $type, array $payload): void
    {
        $this->db->insert(
            'INSERT INTO events_log(type, payload_json) VALUES(:type, :payload)',
            [
                ':type' => $type,
                ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    private function getBalance(string $asset): float
    {
        $row = $this->db->fetchOne('SELECT amount FROM balances WHERE asset = :a', [':a' => $asset]);
        if ($row === null) {
            $this->db->insert('INSERT INTO balances(asset, amount) VALUES(:a, 0)', [':a' => $asset]);
            return 0.0;
        }
        return (float)$row['amount'];
    }

    private function addBalance(string $asset, float $delta): void
    {
        // Compatible with older SQLite versions (no UPSERT syntax).
        $this->db->exec('INSERT OR IGNORE INTO balances(asset, amount) VALUES(:a, 0)', [':a' => $asset]);
        $this->db->exec('UPDATE balances SET amount = amount + :d WHERE asset = :a', [
            ':a' => $asset,
            ':d' => $delta,
        ]);
    }
}
