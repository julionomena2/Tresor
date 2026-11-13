<?php
// Core/Router.php - Gère les URLs et les dirige vers les bons Contrôleurs

namespace App\Core;

class Router {
    private $routes = [];
    private $middleware = [];
    
    // Enregistrer une route GET
    public function get($path, $handler) {
        $this->addRoute('GET', $path, $handler);
    }
    
    // Enregistrer une route POST
    public function post($path, $handler) {
        $this->addRoute('POST', $path, $handler);
    }
    
    // Enregistrer une route PUT
    public function put($path, $handler) {
        $this->addRoute('PUT', $path, $handler);
    }
    
    // Enregistrer une route DELETE
    public function delete($path, $handler) {
        $this->addRoute('DELETE', $path, $handler);
    }
    
    // Ajouter une route
    private function addRoute($method, $path, $handler) {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'middleware' => $this->middleware
        ];
    }
    
    // Grouper des routes avec middleware
    public function group($options, $callback) {
        $this->middleware = $options['middleware'] ?? [];
        $callback($this);
        $this->middleware = [];
    }
    
    // Résoudre la requête
    public function resolve() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        // Trouver la route correspondante
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $this->matchPath($route['path'], $path)) {
                // Exécuter les middlewares
                foreach ($route['middleware'] as $middleware) {
                    if (!$middleware::handle()) {
                        return;
                    }
                }
                
                // Appeler le contrôleur
                $this->callHandler($route['handler']);
                return;
            }
        }
        
        // Aucune route trouvée
        http_response_code(404);
        echo json_encode(['error' => 'Route non trouvée']);
    }
    
    // Vérifier si le chemin correspond
    private function matchPath($routePath, $requestPath) {
        $routePath = rtrim($routePath, '/');
        $requestPath = rtrim($requestPath, '/');
        return $routePath === $requestPath;
    }
    
    // Appeler le contrôleur
    private function callHandler($handler) {
        $controller = new $handler[0]();
        $method = $handler[1];
        $controller->$method();
    }
}