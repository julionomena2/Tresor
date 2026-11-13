<?php
/** logout.php : journalise puis ferme la session. */
require_once __DIR__ . '/includes/auth.php';
deconnexion();
header('Location: ' . BASE_URL . '/login.php');
exit;
