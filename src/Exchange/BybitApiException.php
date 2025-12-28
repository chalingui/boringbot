<?php
declare(strict_types=1);

namespace BoringBot\Exchange;

use RuntimeException;

final class BybitApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpCode,
        public readonly int $retCode,
        public readonly string $retMsg,
        string $message
    ) {
        parent::__construct($message);
    }
}

