<?php

namespace app\core\router;

use app\core\request\Request;

require __DIR__ . "/../../vendor/autoload.php";

class Router
{

    public Request $request;
    protected array $routes = [];

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function get($path, $callback)
    {
        $this->routes['get'][$path] = $callback;
    }

    public function post($path, $callback)
    {
        $this->routes['post'][$path] = $callback;
    }

    public function patch($path, $callback)
    {
        $this->routes['patch'][$path] = $callback;
    }

    public function delete($path, $callback)
    {
        $this->routes['delete'][$path] = $callback;
    }


    public function resolve()
    {

        $path = $this->request->getPath();
        $method = $this->request->getMethod();
        $callback = $this->routes[$method][$path] ?? false;

        if ($callback === false) {
            echo "NOT FOUND";
            http_response_code(404);
            exit;
        }

        echo call_user_func($callback, $path);
    }
}
