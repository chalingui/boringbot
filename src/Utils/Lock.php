<?php
declare(strict_types=1);

namespace BoringBot\Utils;

final class Lock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    public function acquire(): bool
    {
        $this->handle = fopen($this->path, 'c+');
        if ($this->handle === false) {
            return false;
        }
        return flock($this->handle, LOCK_EX | LOCK_NB);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }
}

