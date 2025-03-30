<?php

namespace app\core\application;

use app\core\database\Database;
use app\core\request\Request;
use app\core\router\Router;
use app\core\ocr\MistralOcrClient;
use app\core\controller\OcrController;
use app\core\model\OcrModel;
use app\core\response\Response;


class Application
{

    public Router $router;
    public Request $request;
    public Response $response; 

    public Database $database;

    // models
    public OcrModel $ocr_model; // Instance of the "Fat" model

    // controllers
    public OcrController $ocr_controller;


    public \Dotenv\Dotenv $dotenv;

    public function __construct()
    {
        $this->request = new Request();
        $this->router = new Router($this->request);
        $this->response = new Response();

        $this->database = new Database(
            $_ENV['DB_HOST'],
            $_ENV['DB_NAME'],
            $_ENV['DB_USER'],
            $_ENV['DB_PASS']
        );

        $this->dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . "/../..");
        $this->dotenv->load();

        // $this->ocr = new MistralOcrClient(
        //     api_key: $_ENV['MISTRAL_API_KEY'],
        //     request: $this->request
        // );

        $this->ocr_model = new OcrModel(
            api_key: $_ENV['MISTRAL_API_KEY']
        );

        $this->ocr_controller = new OcrController(
            $this->request,
            $this->response,
            $this->ocr_model // Pass the fat model instance
            // auth: $this->auth // If needed
        );

    }
    public function run()
    {
        $this->router->resolve();
    }
}
