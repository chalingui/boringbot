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
        $this->reconcileAsset('USDT');
    }

    public function reconcileEth(): void
    {
        $this->reconcileAsset('ETH');
    }

    public function reconcileBtc(): void
    {
        $this->reconcileAsset('BTC');
    }

    private function reconcileAsset(string $asset): void
    {
        $bybit = $this->bybit->walletBalance($asset);
        if ($bybit === null) {
            throw new \RuntimeException('Could not fetch Bybit ' . $asset . ' balance.');
        }

        $bot = $this->getBalance($asset);
        $delta = $bybit - $bot;

        $key = strtolower($asset);
        $this->logger->info('Reconcile fetched Bybit balance', [
            'asset' => $asset,
            'bybit' => number_format($bybit, 8, '.', ''),
            'bot' => number_format($bot, 8, '.', ''),
            'delta' => number_format($delta, 8, '.', ''),
            'dry_run' => $this->dryRun,
        ]);

        if (abs($delta) < 1e-9) {
            $this->insertEvent('RECONCILE', [
                'asset' => $asset,
                'bybit_' . $key => number_format($bybit, 8, '.', ''),
                'bot_' . $key => number_format($bot, 8, '.', ''),
                'delta' => 0,
                'note' => 'Already in sync; no update.',
                'dry_run' => $this->dryRun,
            ]);
            return;
        }

        if ($this->dryRun) {
            $this->insertEvent('RECONCILE', [
                'asset' => $asset,
                'bybit_' . $key => number_format($bybit, 8, '.', ''),
                'bot_' . $key => number_format($bot, 8, '.', ''),
                'delta' => number_format($delta, 8, '.', ''),
                'new_bot_' . $key => number_format($bybit, 8, '.', ''),
                'note' => 'Dry-run; would sync ledger to Bybit balance.',
                'dry_run' => true,
            ]);
            return;
        }

        try {
            $this->db->begin();
            $this->addBalance($asset, $delta);
            $this->insertEvent('RECONCILE', [
                'asset' => $asset,
                'bybit_' . $key => number_format($bybit, 8, '.', ''),
                'bot_' . $key => number_format($bot, 8, '.', ''),
                'delta' => number_format($delta, 8, '.', ''),
                'new_bot_' . $key => number_format($bot + $delta, 8, '.', ''),
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
