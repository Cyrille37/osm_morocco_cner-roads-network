<?php

declare(strict_types=1);

namespace Cyrille\RrInspect;

class HistoryFile
{
    protected $file;
    protected $ttl;
    protected $history = [];

    public function __construct(array $config)
    {
        $file = $config['file'];
        if (! is_writable(dirname($file)))
            throw new \InvalidArgumentException('File must be writable "' . $file . '"');

        $this->file = $file;
        if (file_exists($file))
            $this->history = json_decode(file_get_contents($file), true);
        $this->ttl = $config['check_ok_ttl'];
    }

    public function save()
    {
        file_put_contents($this->file, json_encode($this->history));
    }

    public function needUpdate(string $ref, int $time): bool
    {
        if (! isset($this->history[$ref]))
            return true;
        if (! $this->history[$ref]['ok_at'])
            return true;
        if ($this->history[$ref]['ok_at'] + $this->ttl < $time)
            return true;
        return false;
    }

    public function getOkAt(string $ref): int
    {
        return $this->history[$ref]['ok_at'] ?? -1;
    }

    public function getErrorAt(string $ref): int
    {
        return $this->history[$ref]['error_at'] ?? -1;
    }

    public function update(string $ref, bool $hasErrors): void
    {
        $now = time();
        if (! isset($this->history[$ref])) {
            $this->history[$ref] = [
                'ok_at' => $hasErrors ? null : $now,
                'error_at' => $hasErrors ? $now : null,
            ];
        } else {
            $k = $hasErrors ? 'error_at' : 'ok_at';
            $this->history[$ref][$k] = $now;
        }
    }
}
