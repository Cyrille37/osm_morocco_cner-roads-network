<?php

namespace Cyrille\RrInspect;

class DownloadException extends \Exception
{
    public function __construct($url, array $last_error, array $http_header)
    {
        /*
        $last_error : Array (
            [type] => 8
            [message] => Undefined variable: a
            [file] => C:\WWW\index.php
            [line] => 2
        )
        var_dump($http_header):
            array(7) {
            [0]=> string(28) "HTTP/1.1 504 Gateway Timeout"
            [1]=> string(35) "Date: Sat, 19 Apr 2025 14:37:53 GMT"
            [2]=> string(30) "Server: Apache/2.4.62 (Debian)"
            [3]=> string(21) "Vary: Accept-Encoding"
            [4]=> string(19) "Content-Length: 695"
            [5]=> string(17) "Connection: close"
            [6]=> string(38) "Content-Type: text/html; charset=utf-8"
            }

        Download failed: (2) url[https://overpass-api.de/api/interpreter]
            error:[file_get_contents(https://overpass-api.de/api/interpreter): Failed to open stream: HTTP request failed! HTTP/1.1 429 Too Many Requests]
        */

        if (preg_match('{HTTP\/\S*\s(\d{3})}', $http_header[0], $match)) {
            $code = $match[1];
        } else {
            $code = $last_error['type'];
        }

        //$message = 'url[' . $url . '] error:[' . $last_error['message'] . ']';
        $message = $last_error['message'];

        parent::__construct($message, $code);
    }
}
