<?php
/**
 * register.php (NOUVEAU v2)
 * Inscription d'un nouvel utilisateur. Le compte est créé "en attente" :
 * il ne pourra se connecter qu'après validation par l'administrateur.
 */
require_once __DIR__ . '/includes/auth.php';

if (($u = utilisateur_courant()) !== null) {
    header('Location: ' . url_accueil($u['role']));
    exit;
}

$erreur = '';
$valeurs = ['nom_complet' => '', 'username' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valeurs['nom_complet'] = trim($_POST['nom_complet'] ?? '');
    $valeurs['username']    = trim($_POST['username'] ?? '');

    $res = inscrire_utilisateur(
        $valeurs['username'],
        $_POST['password'] ?? '',
        $_POST['confirmation'] ?? '',
        $valeurs['nom_complet']
    );

    if ($res['ok']) {
        header('Location: ' . BASE_URL . '/login.php?inscrit=1');
        exit;
    }
    $erreur = $res['erreur'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Inscription — Gestion des Gardes</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-carte">
        <h1>Demande d'inscription</h1>

        <?php if ($erreur): ?><div class="message-erreur"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

        <form method="post">
            <div class="form-ligne">
                <label for="nom_complet">Nom complet</label>
                <input type="text" id="nom_complet" name="nom_complet" maxlength="100" required autofocus
                       value="<?= htmlspecialchars($valeurs['nom_complet']) ?>">
            </div>
            <div class="form-ligne">
                <label for="username">Identifiant souhaité</label>
                <input type="text" id="username" name="username" maxlength="30" required
                       value="<?= htmlspecialchars($valeurs['username']) ?>">
            </div>
            <div class="form-ligne">
                <label for="password">Mot de passe (8 caractères minimum)</label>
                <input type="password" id="password" name="password" minlength="8" required>
            </div>
            <div class="form-ligne">
                <label for="confirmation">Confirmer le mot de passe</label>
                <input type="password" id="confirmation" name="confirmation" minlength="8" required>
            </div>
            <button type="submit">Envoyer la demande</button>
        </form>

        <p class="login-lien">Déjà un compte ? <a href="<?= BASE_URL ?>/login.php">Se connecter</a></p>
    </div>
</body>
</html>
