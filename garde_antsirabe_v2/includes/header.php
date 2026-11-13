<?php
/**
 * includes/header.php  (v2)
 * En-tête HTML + barre de navigation ADAPTÉE AU RÔLE de l'utilisateur.
 * Variable optionnelle avant inclusion : $titre_page
 */
if (!isset($titre_page)) {
    $titre_page = 'Gestion des gardes';
}
$page_courante = basename($_SERVER['PHP_SELF']);
$utilisateur_h = utilisateur_courant();

// Liens du menu : [chemin, libellé, badge (nombre)]
$liens_nav = [];
if ($utilisateur_h && $utilisateur_h['role'] === 'admin') {
    $liens_nav = [
        ['admin/index.php',        'Tableau de bord', 0],
        ['admin/agents.php',       'Agents', 0],
        ['admin/pointages.php',    'Pointages', 0],
        ['user/pointage.php',      'Saisie pointage', 0],
        ['admin/calcul_pdf.php',   'Indemnités & PDF', 0],
        ['admin/utilisateurs.php', 'Utilisateurs', compter_utilisateurs_en_attente()],
        ['admin/parametres.php',   'Paramètres', 0],
        ['admin/historique.php',   'Historique', 0],
    ];
} elseif ($utilisateur_h) {
    $liens_nav = [['user/pointage.php', 'Pointage', 0]];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($titre_page) ?> — TG Antsirabe</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<header class="topbar">
    <div class="topbar-titre">Gestion des Gardes — 45 TG Antsirabe</div>
    <?php if ($utilisateur_h): ?>
        <div class="topbar-user">
            <strong><?= htmlspecialchars($utilisateur_h['nom_complet']) ?></strong>
            <span class="badge badge-role"><?= $utilisateur_h['role'] === 'admin' ? 'Administrateur' : 'Utilisateur' ?></span>
            &nbsp;|&nbsp; <a href="<?= BASE_URL ?>/logout.php">Se déconnecter</a>
        </div>
    <?php endif; ?>
</header>

<?php if (!empty($liens_nav)): ?>
<nav class="navbar">
    <?php foreach ($liens_nav as [$chemin, $libelle, $badge]): ?>
        <a href="<?= BASE_URL . '/' . $chemin ?>" class="<?= basename($chemin) === $page_courante ? 'actif' : '' ?>">
            <?= htmlspecialchars($libelle) ?>
            <?php if ($badge > 0): ?><span class="nav-badge"><?= (int) $badge ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<main class="conteneur">
