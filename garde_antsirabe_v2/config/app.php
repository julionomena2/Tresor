<?php
/**
 * config/app.php
 * -------------------------------------------------------------
 * Calcule automatiquement BASE_URL = chemin web de l'application.
 * Ex : si l'app est dans C:\xampp\htdocs\garde_antsirabe
 *      alors BASE_URL = "/garde_antsirabe"
 * Utilisé pour construire les liens (CSS, menus, redirections)
 * depuis n'importe quel sous-dossier (admin/, user/...).
 * -------------------------------------------------------------
 */
if (!defined('BASE_URL')) {
    // Normalise les chemins (Windows utilise "\" , le web utilise "/")
    $racine_web = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $racine_app = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));

    if ($racine_web !== '' && stripos($racine_app, $racine_web) === 0) {
        define('BASE_URL', rtrim(substr($racine_app, strlen($racine_web)), '/'));
    } else {
        define('BASE_URL', '/garde_antsirabe'); // valeur de secours
    }
}
