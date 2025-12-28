<?php
declare(strict_types=1);

namespace BoringBot\Bot;

use BoringBot\DB\Database;
use BoringBot\Utils\Logger;
use Throwable;

final class DcaBot
{
    public function __construct(
        private readonly Database $db,
        private readonly PurchaseManager $purchases,
        private readonly Logger $logger,
    ) {
    }

    public function run(): int
    {
        try {
            $this->purchases->tick();
            return 0;
        } catch (Throwable $e) {
            $this->logger->error('Bot run failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            $this->db->insert('INSERT INTO events_log(type, payload_json) VALUES(:t, :p)', [
                ':t' => 'ERROR',
                ':p' => json_encode(['error' => $e->getMessage(), 'class' => get_class($e)]),
            ]);
            return 1;
        }
    }
}

