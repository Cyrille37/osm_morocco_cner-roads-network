<?php
namespace Cyrille\RrInspect;

class DownloadException extends \Exception
{
    public function __construct($url, array $last_error, array $http_header)
    {
        /*
        $last_error : Array
        (
            [type] => 8
            [message] => Undefined variable: a
            [file] => C:\WWW\index.php
            [line] => 2
        )
        */
        /*
        var_dump($http_header);
        $e = $last_error;
        $error = (isset($e) && isset($e['message']) && $e['message'] != "") ?
            $e['message'] : "Check that the file exists and can be read.";
        throw new \Exception('Failed to get "' . $url . '" error: ' . $error);
        */
        $message = 'url['.$url.'] error:['.$last_error['message'] .']';
        $code = $last_error['type'] ;

        parent::__construct($message, $code);
    }
}