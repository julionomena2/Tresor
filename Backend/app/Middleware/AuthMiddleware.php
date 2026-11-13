<?php
// Middleware/AuthMiddleware.php - Vérifie si l'utilisateur est connecté

namespace App\Middleware;

class AuthMiddleware {
    
    public static function handle() {
        // Récupérer le token depuis les headers
        $headers = getallheaders();
        $token = $headers['Authorization'] ?? '';
        $token = str_replace('Bearer ', '', $token);
        
        // Vérifier le token (simplifié)
        if (empty($token)) {
            http_response_code(401);
            echo json_encode(['error' => 'Non authentifié']);
            return false;
        }
        
        // Vérifier la validité du token
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            http_response_code(401);
            echo json_encode(['error' => 'Token invalide']);
            return false;
        }
        
        // Vérifier l'expiration
        $payload = json_decode(base64_decode($parts[1]), true);
        if ($payload['exp'] < time()) {
            http_response_code(401);
            echo json_encode(['error' => 'Token expiré']);
            return false;
        }
        
        // Stocker l'ID utilisateur pour l'utiliser plus tard
        $_SESSION['user_id'] = $payload['user_id'];
        
        return true;
    }
}