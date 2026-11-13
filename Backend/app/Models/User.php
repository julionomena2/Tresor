<?php
// Models/User.php - Gère les interactions avec la table users

namespace App\Models;

class User {
    private static $table = 'users';
    
    // Trouver un utilisateur par email
    public static function findByEmail($email) {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM " . self::$table . " WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch();
    }
    
    // Créer un nouvel utilisateur
    public static function create($data) {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO " . self::$table . " (email, password, name, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$data['email'], $data['password'], $data['name']]);
        return $db->lastInsertId();
    }
    
    // Mettre à jour un utilisateur
    public static function update($id, $data) {
        $db = Database::getInstance();
        $sets = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $sets[] = "$key = ?";
            $params[] = $value;
        }
        $params[] = $id;
        
        $stmt = $db->prepare("
            UPDATE " . self::$table . " 
            SET " . implode(', ', $sets) . " 
            WHERE id = ?
        ");
        return $stmt->execute($params);
    }
    
    // Supprimer un utilisateur
    public static function delete($id) {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM " . self::$table . " WHERE id = ?");
        return $stmt->execute([$id]);
    }
}