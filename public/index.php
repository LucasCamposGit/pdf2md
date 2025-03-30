<?php

declare(strict_types=1);

namespace app;

use app\core\application\Application;

require __DIR__ . "/../vendor/autoload.php";

set_error_handler("app\\core\\error\\ErrorHandler::handleError");
set_exception_handler("app\\core\\error\\ErrorHandler::handleException");
date_default_timezone_set("America/Sao_Paulo");

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type, X-Filename");
header("Access-Control-Allow-Credentials: true");
header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    echo json_encode(["message" => "ok"]);
    exit;
}

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . "/../");
$dotenv->load();

// app
$app = new Application();

$app->router->post("pdf2md", [$app->ocr_controller, 'handleProcessPdf']);

$app->run();