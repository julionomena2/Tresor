<?php
// index.php - Tout passe par ici !

// 1. ACTIVER LES ERREURS (en développement)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 2. CHARGER LE ROUTEUR
require_once __DIR__ . '/../app/Core/Router.php';
require_once __DIR__ . '/../routes/api.php';

// 3. GÉRER LES HEADERS CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// 4. RÉPONDRE AUX REQUÊTES OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 5. LANCER LE ROUTEUR
$router->resolve();>