<?php

namespace app\core\error;

use ErrorException;
use Exception;
use Throwable;

class ErrorHandler {

    public static function handleError(
        int $errno,
        string $errstr,
        string $errfile,
        int $errline
    ): void  {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    public static function handleException(Throwable $exception): void {

        $file = fopen("debug.json", "a");

        fwrite($file, PHP_EOL . json_encode([
            "code" => $exception->getCode(),
            "message" => $exception->getMessage(),
            "file" => $exception->getFile(),
            "line" => $exception->getLine()
        ]) . PHP_EOL);
        fclose($file);

        http_response_code(500);
        echo json_encode([
            "code" => $exception->getCode(),
            "message" => $exception->getMessage(),
            "file" => $exception->getFile(),
            "line" => $exception->getLine()
        ]);
    }
}