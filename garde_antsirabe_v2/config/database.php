<?php
/**
 * config/database.php
 * -------------------------------------------------------------
 * Etablit la connexion PDO vers la base MySQL.
 * A adapter si tes identifiants XAMPP sont differents
 * (par defaut sur XAMPP : utilisateur "root", mot de passe vide).
 * -------------------------------------------------------------
 */

// Parametres de connexion (a modifier si besoin)
define('DB_HOST', 'localhost');
define('DB_NAME', 'garde_antsirabe_db');
define('DB_USER', 'root');
define('DB_PASS', '');       // vide par defaut sur XAMPP
define('DB_CHARSET', 'utf8mb4');

/**
 * Retourne une connexion PDO unique (singleton) vers la base.
 * Toutes les pages appellent cette fonction pour executer leurs requetes.
 */
function get_pdo(): PDO
{
    static $pdo = null; // conserve la connexion en memoire entre les appels

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // les erreurs SQL levent une exception
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // resultats sous forme de tableaux associatifs
            PDO::ATTR_EMULATE_PREPARES   => false,                  // vraies requetes preparees (securite)
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // On arrete tout si la connexion echoue : rien ne peut fonctionner sans la base
            die('Erreur de connexion a la base de donnees : ' . $e->getMessage());
        }
    }

    return $pdo;
}
