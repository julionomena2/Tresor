<?php
/**
 * install/setup_admin.php  (v2) - INSTALLATION NEUVE UNIQUEMENT
 * -------------------------------------------------------------
 * A lancer UNE SEULE FOIS après avoir importé sql/schema.sql.
 * Crée le compte administrateur (mot de passe haché) dans "utilisateurs".
 *
 * Si tu as MIGRÉ depuis la v1 (sql/migration_v2.sql), ton ancien compte
 * admin existe déjà : ce script n'est PAS nécessaire.
 *
 * Après usage : SUPPRIME ce fichier (ou tout le dossier install/).
 * -------------------------------------------------------------
 */
require_once __DIR__ . '/../config/database.php';

$username = 'admin';
$password = 'W0SzN!HkTAI#'; // à changer si tu veux, avant de lancer ce script

$pdo = get_pdo();

if ((int) $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'admin'")->fetchColumn() > 0) {
    die('Un compte administrateur existe déjà. Rien à faire. Supprime ce fichier.');
}

$stmt = $pdo->prepare(
    "INSERT INTO utilisateurs (username, password, nom_complet, role, statut)
     VALUES (:u, :p, 'Administrateur', 'admin', 'actif')"
);
$stmt->execute(['u' => $username, 'p' => password_hash($password, PASSWORD_BCRYPT)]);

echo '<h2>Compte administrateur créé avec succès.</h2>';
echo '<p>Identifiant : <strong>' . htmlspecialchars($username) . '</strong></p>';
echo '<p style="color:red; font-weight:bold;">Supprime maintenant ce fichier (install/setup_admin.php), puis va sur login.php</p>';
