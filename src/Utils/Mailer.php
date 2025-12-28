<?php
declare(strict_types=1);

namespace BoringBot\Utils;

use RuntimeException;

final class Mailer
{
    public function __construct(
        private readonly Logger $logger,
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $pass,
        private readonly string $encryption, // "starttls" | "ssl" | ""
        private readonly bool $dryRun,
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->user !== '' && $this->pass !== '';
    }

    public function send(string $from, string $to, string $subject, string $bodyText): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('SMTP is not configured.');
        }
        if ($from === '' || $to === '') {
            throw new RuntimeException('Missing from/to for email.');
        }

        if ($this->dryRun) {
            $this->logger->info('DRY-RUN email', [
                'to' => $to,
                'from' => $from,
                'subject' => $subject,
                'body_bytes' => strlen($bodyText),
            ]);
            return;
        }

        $this->smtpSend($from, $to, $subject, $bodyText);
    }

    private function smtpSend(string $from, string $to, string $subject, string $bodyText): void
    {
        $transport = match (strtolower($this->encryption)) {
            'ssl', 'tls' => 'ssl://',
            default => '',
        };

        $socket = stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            throw new RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $this->timeoutSeconds);
        try {
            $this->expect($socket, 220);
            $this->cmd($socket, 'EHLO boringbot');
            $ehlo = $this->readMultiline($socket);

            if (strtolower($this->encryption) === 'starttls') {
                $this->cmd($socket, 'STARTTLS');
                $this->expect($socket, 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS failed.');
                }
                $this->cmd($socket, 'EHLO boringbot');
                $ehlo = $this->readMultiline($socket);
            }

            if (!str_contains(implode("\n", $ehlo), 'AUTH')) {
                // Still try AUTH LOGIN; some servers don't advertise clearly.
            }

            $this->cmd($socket, 'AUTH LOGIN');
            $this->expect($socket, 334);
            $this->cmd($socket, base64_encode($this->user));
            $this->expect($socket, 334);
            $this->cmd($socket, base64_encode($this->pass));
            $this->expect($socket, 235);

            $this->cmd($socket, 'MAIL FROM:<' . $from . '>');
            $this->expect($socket, 250);
            $this->cmd($socket, 'RCPT TO:<' . $to . '>');
            $this->expect($socket, 250);
            $this->cmd($socket, 'DATA');
            $this->expect($socket, 354);

            $headers = [
                'From: ' . $from,
                'To: ' . $to,
                'Subject: ' . $this->encodeHeader($subject),
                'Date: ' . date(DATE_RFC2822),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];

            $msg = implode("\r\n", $headers) . "\r\n\r\n" . $bodyText;
            $msg = str_replace("\r\n", "\n", $msg);
            $msg = str_replace("\r", "\n", $msg);
            $lines = explode("\n", $msg);
            foreach ($lines as $line) {
                if (str_starts_with($line, '.')) {
                    $line = '.' . $line;
                }
                fwrite($socket, $line . "\r\n");
            }
            fwrite($socket, ".\r\n");
            $this->expect($socket, 250);

            $this->cmd($socket, 'QUIT');
            $this->readLine($socket);
        } finally {
            fclose($socket);
        }
    }

    private function cmd($socket, string $command): void
    {
        fwrite($socket, $command . "\r\n");
    }

    private function expect($socket, int $code): void
    {
        $line = $this->readLine($socket);
        $got = (int)substr($line, 0, 3);
        if ($got !== $code) {
            throw new RuntimeException("SMTP expected {$code}, got: {$line}");
        }
        // Handle multiline for the same code (e.g., 250-...).
        if (strlen($line) >= 4 && $line[3] === '-') {
            $this->readMultiline($socket, $code);
        }
    }

    private function readMultiline($socket, ?int $code = null): array
    {
        $lines = [];
        while (true) {
            $line = $this->readLine($socket);
            $lines[] = $line;
            if ($code !== null) {
                $got = (int)substr($line, 0, 3);
                if ($got !== $code) {
                    throw new RuntimeException("SMTP expected {$code}, got: {$line}");
                }
            }
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $lines;
    }

    private function readLine($socket): string
    {
        $line = fgets($socket);
        if ($line === false) {
            throw new RuntimeException('SMTP read failed.');
        }
        return rtrim($line, "\r\n");
    }

    private function encodeHeader(string $value): string
    {
        // Minimal RFC 2047 support for UTF-8 subjects.
        if (preg_match('/[\\x80-\\xFF]/', $value) !== 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}

