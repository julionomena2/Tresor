<?php
// Controllers/DashboardController.php

namespace App\Controllers;

use App\Models\Database;

class DashboardController {
    
    // 📌 ACTION: Dashboard (GET /dashboard)
    public function index() {
        // Récupérer l'utilisateur depuis le token (simplifié)
        $userId = $this->getUserIdFromToken();
        
        // Récupérer des données
        $db = Database::getInstance();
        $stats = $db->query("
            SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users
            FROM users
        ")->fetch();
        
        $this->jsonResponse(true, 'Dashboard data', [
            'stats' => $stats,
            'user_id' => $userId
        ]);
    }
    
    // 📌 ACTION: Statistiques (GET /dashboard/stats)
    public function stats() {
        $db = Database::getInstance();
        $stats = $db->query("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as count
            FROM users
            GROUP BY DATE(created_at)
            ORDER BY date DESC
            LIMIT 7
        ")->fetchAll();
        
        $this->jsonResponse(true, 'Statistiques', $stats);
    }
    
    private function getUserIdFromToken() {
        // Simuler la récupération depuis le token JWT
        return 1;
    }
    
    private function jsonResponse($success, $message, $data = null) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data
        ]);
    }
}