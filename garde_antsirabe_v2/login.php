<?php
/**
 * login.php (v2)
 * Page de connexion UNIQUE : l'utilisateur est redirigé selon son rôle
 * (admin -> tableau de bord, utilisateur -> pointage).
 */
require_once __DIR__ . '/includes/auth.php';

if (($u = utilisateur_courant()) !== null) {
    header('Location: ' . url_accueil($u['role']));
    exit;
}

$erreur = '';
$info = isset($_GET['inscrit'])
    ? 'Inscription enregistrée. Un administrateur doit valider votre compte avant que vous puissiez vous connecter.'
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $erreur = 'Veuillez remplir tous les champs.';
    } else {
        $res = tenter_connexion($username, $password);
        if ($res['ok']) {
            header('Location: ' . url_accueil(utilisateur_courant()['role']));
            exit;
        }
        $erreur = $res['erreur'];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Connexion — Gestion des Gardes</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-carte">
        <h1>Gestion des Gardes<br>45 TG Antsirabe</h1>

        <?php if ($info): ?><div class="message-succes"><?= htmlspecialchars($info) ?></div><?php endif; ?>
        <?php if ($erreur): ?><div class="message-erreur"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

        <form method="post">
            <div class="form-ligne">
                <label for="username">Identifiant</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>
            <div class="form-ligne">
                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">Se connecter</button>
        </form>

        <p class="login-lien">Pas encore de compte ? <a href="<?= BASE_URL ?>/register.php">Demander une inscription</a></p>
    </div>
</body>
</html>
