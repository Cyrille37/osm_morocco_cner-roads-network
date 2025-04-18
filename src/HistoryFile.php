<?php
declare(strict_types=1);

namespace Cyrille\RrInspect;

class HistoryFile
{
    protected $file ;
    protected $history = [] ;

    public function __construct($file)
    {
        if( ! is_writable(dirname($file) ) )
            throw new \InvalidArgumentException('File must be writable "'.$file.'"');

        $this->file = $file ;
        if( file_exists($file) )
            $this->history = json_decode( file_get_contents($file), true);
    }

    public function save()
    {
        file_put_contents( $this->file, json_encode($this->history));
    }

    public function update( string $ref, bool $hasErrors ):void
    {
        $now = time();
        if( ! isset($this->history[$ref]))
        {
            $this->history[$ref] = [
                'ok_at' => $hasErrors ? null : $now,
                'error_at' => $hasErrors ? $now : null,
            ];
        }
        else
        {
            $k = $hasErrors ? 'error_at' : 'ok_at' ;
            $this->history[$ref][$k] = $now ;
        }
    }
}
