<?php
// routes/api.php - Toutes les routes de l'API

use App\Controllers\AuthController;
use App\Controllers\UserController;
use App\Controllers\DashboardController;
use App\Middleware\AuthMiddleware;

// 1. ROUTES PUBLIQUES (Sans authentification)
$router->post('/login', [AuthController::class, 'login']);
$router->post('/register', [AuthController::class, 'register']);

// 2. ROUTES PROTÉGÉES (Avec authentification)
$router->group(['middleware' => AuthMiddleware::class], function($router) {
    // Routes du dashboard
    $router->get('/dashboard', [DashboardController::class, 'index']);
    $router->get('/dashboard/stats', [DashboardController::class, 'stats']);
    
    // Routes utilisateur
    $router->get('/user/profile', [UserController::class, 'profile']);
    $router->put('/user/update', [UserController::class, 'update']);
    $router->post('/logout', [AuthController::class, 'logout']);
});