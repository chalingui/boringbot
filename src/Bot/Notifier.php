<?php
declare(strict_types=1);

namespace BoringBot\Bot;

use BoringBot\DB\Database;
use BoringBot\Utils\Logger;
use BoringBot\Utils\Mailer;
use DateTimeImmutable;
use Throwable;

final class Notifier
{
    public function __construct(
        private readonly Database $db,
        private readonly Logger $logger,
        private readonly Mailer $mailer,
        private readonly bool $enabled,
        private readonly string $emailTo,
        private readonly string $emailFrom,
        private readonly int $cooldownMinutes,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->emailTo !== '' && $this->emailFrom !== '' && $this->mailer->isConfigured();
    }

    public function purchaseCreated(int $purchaseId, float $buyUsdt, string $symbol): void
    {
        $this->sendEvent(
            key: 'purchase_created_' . $purchaseId,
            subject: "[boringbot] Compra #{$purchaseId} creada ({$symbol})",
            body: "Compra #{$purchaseId} creada.\nSymbol: {$symbol}\nMonto: {$buyUsdt} USDT\n"
        );
    }

    public function sold(int $purchaseId, float $sellUsdt, float $profitUsdt, float $profitUsdc): void
    {
        $this->sendEvent(
            key: 'sold_' . $purchaseId,
            subject: "[boringbot] Venta ejecutada (Compra #{$purchaseId})",
            body: "Venta ejecutada para Compra #{$purchaseId}.\nSell: {$sellUsdt} USDT\nProfit: {$profitUsdt} USDT\nConvertido: {$profitUsdc} USDC\n"
        );
    }

    public function insufficientFunds(float $needUsdt, float $haveUsdt): void
    {
        // Cooldown-based, not per-run spam.
        $key = 'no_funds';
        if (!$this->shouldSendCooldown($key)) {
            return;
        }

        $this->sendEvent(
            key: $key,
            subject: '[boringbot] Sin USDT para comprar',
            body: "No hay USDT suficiente para ejecutar la compra.\nNecesita: {$needUsdt} USDT\nDisponible (ledger): {$haveUsdt} USDT\n"
        );
    }

    private function sendEvent(string $key, string $subject, string $body): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $this->mailer->send($this->emailFrom, $this->emailTo, $subject, $body);
            $this->insertEvent('NOTIFY_EMAIL', [
                'key' => $key,
                'to' => $this->emailTo,
                'from' => $this->emailFrom,
                'subject' => $subject,
            ]);
            $this->setMeta('notify_last_sent_' . $key, (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM));
        } catch (Throwable $e) {
            $this->logger->error('Email notify failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            $this->insertEvent('NOTIFY_EMAIL_ERROR', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function shouldSendCooldown(string $key): bool
    {
        if ($this->cooldownMinutes <= 0) {
            return true;
        }

        $last = $this->getMeta('notify_last_sent_' . $key);
        if ($last === null || $last === '') {
            return true;
        }

        try {
            $lastDt = new DateTimeImmutable($last);
        } catch (Throwable) {
            return true;
        }

        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $diffSeconds = $now->getTimestamp() - $lastDt->getTimestamp();
        return $diffSeconds >= ($this->cooldownMinutes * 60);
    }

    private function getMeta(string $k): ?string
    {
        $row = $this->db->fetchOne('SELECT v FROM meta WHERE k = :k', [':k' => $k]);
        return $row === null ? null : (string)$row['v'];
    }

    private function setMeta(string $k, string $v): void
    {
        $this->db->exec(
            'INSERT INTO meta(k, v) VALUES(:k, :v) ON CONFLICT(k) DO UPDATE SET v = :v2',
            [':k' => $k, ':v' => $v, ':v2' => $v]
        );
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
}

