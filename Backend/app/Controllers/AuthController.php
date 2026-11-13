<?php
// Controllers/AuthController.php - Gère l'authentification

namespace App\Controllers;

use App\Models\User;
use App\Models\Database;

class AuthController {
    
    // 📌 ACTION: Connexion (POST /login)
    public function login() {
        // 1. Récupérer les données de la requête
        $input = json_decode(file_get_contents('php://input'), true);
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';
        
        // 2. Valider les données
        if (empty($email) || empty($password)) {
            $this->jsonResponse(false, 'Email et mot de passe requis');
            return;
        }
        
        // 3. Chercher l'utilisateur dans la BDD
        $user = User::findByEmail($email);
        
        // 4. Vérifier les identifiants
        if (!$user || !password_verify($password, $user['password'])) {
            $this->jsonResponse(false, 'Identifiants incorrects');
            return;
        }
        
        // 5. Générer un token (JWT simplifié)
        $token = $this->generateToken($user);
        
        // 6. Répondre avec succès
        $this->jsonResponse(true, 'Connexion réussie', [
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name']
            ]
        ]);
    }
    
    // 📌 ACTION: Inscription (POST /register)
    public function register() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Valider les données
        if (empty($input['email']) || empty($input['password']) || empty($input['name'])) {
            $this->jsonResponse(false, 'Tous les champs sont requis');
            return;
        }
        
        // Vérifier si l'email existe déjà
        if (User::findByEmail($input['email'])) {
            $this->jsonResponse(false, 'Cet email est déjà utilisé');
            return;
        }
        
        // Créer l'utilisateur
        $userData = [
            'email' => $input['email'],
            'password' => password_hash($input['password'], PASSWORD_DEFAULT),
            'name' => $input['name']
        ];
        
        $userId = User::create($userData);
        
        if ($userId) {
            $this->jsonResponse(true, 'Inscription réussie');
        } else {
            $this->jsonResponse(false, 'Erreur lors de l\'inscription');
        }
    }
    
    // 📌 ACTION: Déconnexion (POST /logout)
    public function logout() {
        // Dans une vraie API, on pourrait invalider le token
        $this->jsonResponse(true, 'Déconnexion réussie');
    }
    
    // 📌 UTILITAIRE: Générer un token
    private function generateToken($user) {
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'exp' => time() + 3600 // Expire dans 1 heure
        ]));
        $signature = base64_encode(hash_hmac('sha256', "$header.$payload", 'secret_key', true));
        
        return "$header.$payload.$signature";
    }
    
    // 📌 UTILITAIRE: Réponse JSON
    private function jsonResponse($success, $message, $data = null) {
        header('Content-Type: application/json');
        $response = [
            'success' => $success,
            'message' => $message,
            'data' => $data
        ];
        echo json_encode($response);
    }
}