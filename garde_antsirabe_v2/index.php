<?php
/** index.php : point d'entrée, redirige selon le rôle (ou vers la connexion). */
require_once __DIR__ . '/includes/auth.php';

$u = utilisateur_courant();
header('Location: ' . ($u ? url_accueil($u['role']) : BASE_URL . '/login.php'));
exit;
