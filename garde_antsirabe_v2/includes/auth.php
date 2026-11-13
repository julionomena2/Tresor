<?php
/**
 * includes/auth.php  (VERSION 2 - gestion des rôles)
 * -------------------------------------------------------------
 * Session, connexion, inscription, protection des pages par rôle.
 *
 * Rôles :
 *   'admin'       -> gère agents, utilisateurs, paramètres, PDF, historique
 *   'utilisateur' -> s'occupe du pointage
 *
 * Utilisation en haut d'une page :
 *   exiger_connexion();               // admin OU utilisateur
 *   exiger_connexion(['admin']);      // admin uniquement
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Retourne l'utilisateur connecté (rechargé depuis la base à chaque requête),
 * ou null. Si l'admin suspend/supprime un compte, la session de cet utilisateur
 * est invalidée immédiatement à sa prochaine action.
 */
function utilisateur_courant(bool $recharger = false): ?array
{
    static $cache = null;
    static $charge = false;

    if ($recharger) { $charge = false; $cache = null; }
    if ($charge) return $cache;
    $charge = true;

    if (empty($_SESSION['user_id'])) return $cache = null;

    $stmt = get_pdo()->prepare('SELECT id, username, nom_complet, role, statut FROM utilisateurs WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $u = $stmt->fetch();

    if (!$u || $u['statut'] !== 'actif') {
        $_SESSION = [];            // compte supprimé ou bloqué : on coupe la session
        return $cache = null;
    }
    return $cache = $u;
}

function est_connecte(): bool
{
    return utilisateur_courant() !== null;
}

/** Page d'accueil selon le rôle. */
function url_accueil(string $role): string
{
    return $role === 'admin' ? BASE_URL . '/admin/index.php' : BASE_URL . '/user/pointage.php';
}

/**
 * Bloque la page si non connecté (-> login) ou si le rôle n'est pas autorisé (-> 403).
 */
function exiger_connexion(array $roles_autorises = ['admin', 'utilisateur']): void
{
    $u = utilisateur_courant();
    if ($u === null) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    if (!in_array($u['role'], $roles_autorises, true)) {
        http_response_code(403);
        die('Accès refusé : cette page n\'est pas accessible avec votre profil. <a href="' . htmlspecialchars(url_accueil($u['role'])) . '">Retour</a>');
    }
}

/**
 * Tente une connexion. Retourne ['ok' => bool, 'erreur' => string].
 * Les tentatives (réussies, échouées, refusées) sont journalisées.
 */
function tenter_connexion(string $username, string $password): array
{
    $stmt = get_pdo()->prepare('SELECT * FROM utilisateurs WHERE username = :u');
    $stmt->execute(['u' => $username]);
    $u = $stmt->fetch();

    // Identifiant inconnu ou mauvais mot de passe (même message pour ne rien révéler)
    if (!$u || !password_verify($password, $u['password'])) {
        journaliser('connexion_echec', 'utilisateurs', null,
            'Tentative de connexion échouée pour l\'identifiant « ' . mb_substr($username, 0, 50) . ' »',
            null, null, ['id' => null, 'username' => $username, 'role' => 'inconnu']);
        return ['ok' => false, 'erreur' => 'Identifiant ou mot de passe incorrect.'];
    }

    // Mot de passe correct mais compte non actif : message explicite
    $messages = [
        'en_attente' => 'Votre inscription est en attente de validation par l\'administrateur.',
        'refuse'     => 'Votre demande d\'inscription a été refusée. Contactez l\'administrateur.',
        'suspendu'   => 'Votre compte est suspendu. Contactez l\'administrateur.',
    ];
    if ($u['statut'] !== 'actif') {
        journaliser('connexion_refusee', 'utilisateurs', (string) $u['id'],
            'Connexion refusée (compte ' . $u['statut'] . ')', null, null, $u);
        return ['ok' => false, 'erreur' => $messages[$u['statut']] ?? 'Compte inactif.'];
    }

    session_regenerate_id(true); // protège contre la fixation de session
    $_SESSION['user_id'] = (int) $u['id'];
    utilisateur_courant(true);

    journaliser('connexion', 'utilisateurs', (string) $u['id'], 'Connexion', null, null, $u);
    return ['ok' => true, 'erreur' => ''];
}

/**
 * Crée une demande d'inscription (statut "en_attente", rôle "utilisateur").
 * Le compte ne pourra se connecter qu'après validation par l'admin.
 */
function inscrire_utilisateur(string $username, string $password, string $confirmation, string $nom_complet): array
{
    $username    = trim($username);
    $nom_complet = trim($nom_complet);

    if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $username)) {
        return ['ok' => false, 'erreur' => 'Identifiant : 3 à 30 caractères (lettres, chiffres, point, tiret, underscore).'];
    }
    if (mb_strlen($nom_complet) < 2 || mb_strlen($nom_complet) > 100) {
        return ['ok' => false, 'erreur' => 'Le nom complet est obligatoire (2 à 100 caractères).'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'erreur' => 'Le mot de passe doit contenir au moins 8 caractères.'];
    }
    if ($password !== $confirmation) {
        return ['ok' => false, 'erreur' => 'Les deux mots de passe ne correspondent pas.'];
    }

    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM utilisateurs WHERE username = :u');
    $stmt->execute(['u' => $username]);
    if ((int) $stmt->fetchColumn() > 0) {
        return ['ok' => false, 'erreur' => 'Cet identifiant est déjà utilisé.'];
    }

    $stmt = $pdo->prepare(
        "INSERT INTO utilisateurs (username, password, nom_complet, role, statut)
         VALUES (:u, :p, :n, 'utilisateur', 'en_attente')"
    );
    $stmt->execute(['u' => $username, 'p' => password_hash($password, PASSWORD_BCRYPT), 'n' => $nom_complet]);
    $id = (int) $pdo->lastInsertId();

    journaliser('inscription', 'utilisateurs', (string) $id,
        'Demande d\'inscription de « ' . $username . ' » (' . $nom_complet . ')',
        null, ['username' => $username, 'nom_complet' => $nom_complet, 'statut' => 'en_attente'],
        ['id' => $id, 'username' => $username, 'role' => 'utilisateur']);

    return ['ok' => true, 'erreur' => ''];
}

/** Ferme la session (et la journalise). */
function deconnexion(): void
{
    $u = utilisateur_courant();
    if ($u) {
        journaliser('deconnexion', 'utilisateurs', (string) $u['id'], 'Déconnexion');
    }
    $_SESSION = [];
    session_destroy();
    utilisateur_courant(true);
}
