<?php

namespace app\core\request;

require __DIR__ . "/../../vendor/autoload.php";


class Request
{

    public function getPath(): string
    {

        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $parts = explode("/", $path);
        $route = $parts[1] ?? "/";
        return $route;
    }

    public function getMethod(): string
    {
        return strtolower($_SERVER['REQUEST_METHOD']);
    }

    public function getRequestUri(): array
    {
        $uri = $_SERVER['REQUEST_URI'];
        $uri = parse_url($uri, PHP_URL_PATH);
        return explode("/", $uri);
    }

    public function getSpecificHeader(string $header)
    {

        // $headers = apache_request_headers();

        // if (isset($headers[$header])) return $headers[$header];

        // return $_SERVER[$header];

        // new code

        // Try apache_request_headers() first (if available)
        if (function_exists('apache_request_headers')) {
            $apacheHeaders = apache_request_headers();
            // Normalize apache header keys to lowercase for case-insensitive comparison
            $apacheHeaders = array_change_key_case($apacheHeaders, CASE_LOWER);
            $lowerHeader = strtolower($header);
            if (isset($apacheHeaders[$lowerHeader])) {
                return $apacheHeaders[$lowerHeader];
            }
        }

        // If not found or apache_request_headers not available, check $_SERVER
        // Common formats: 'Content-Type', 'HTTP_X_FILENAME'
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $header));

        if (isset($_SERVER[$header])) { // Check original case first (less common)
            return $_SERVER[$header];
        } elseif (isset($_SERVER[$serverKey])) { // Check standard HTTP_ prefixed key
            return $_SERVER[$serverKey];
        }

        // Header not found in common locations
        return null;
    }

    public function getRequestData(): array
    {
        return (array) json_decode(file_get_contents("php://input"), true);
    }

    public function getRawRequestData(): string
    {
        return file_get_contents("php://input");
    }
}
