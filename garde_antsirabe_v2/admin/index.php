<?php
/**
 * admin/index.php  (CÔTÉ ADMINISTRATEUR)
 * Tableau de bord : chiffres clés, inscriptions en attente, dernières actions.
 */
require_once __DIR__ . '/../includes/auth.php';
exiger_connexion(['admin']);

$pdo = get_pdo();

$nb_agents   = (int) $pdo->query("SELECT COUNT(*) FROM agents WHERE statut = 'actif'")->fetchColumn();
$nb_attente  = compter_utilisateurs_en_attente();
$nb_users    = (int) $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'utilisateur' AND statut = 'actif'")->fetchColumn();
$nb_gardes   = (int) $pdo->query("SELECT COUNT(*) FROM pointages WHERE date_debut >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$nb_op       = (int) $pdo->query('SELECT COUNT(*) FROM ordres_paiement')->fetchColumn();

$dernieres = $pdo->query('SELECT * FROM historique ORDER BY id DESC LIMIT 10')->fetchAll();

$titre_page = 'Tableau de bord';
require __DIR__ . '/../includes/header.php';
?>

<h1>Tableau de bord</h1>

<?php if ($nb_attente > 0): ?>
    <div class="info-box">
        <strong><?= $nb_attente ?></strong> inscription(s) en attente de votre validation.
        <a href="utilisateurs.php">Traiter maintenant →</a>
    </div>
<?php endif; ?>

<div class="stat-grille">
    <div class="stat"><div class="nombre"><?= $nb_agents ?></div><div class="libelle">Agents actifs</div></div>
    <div class="stat"><div class="nombre"><?= $nb_users ?></div><div class="libelle">Utilisateurs actifs</div></div>
    <div class="stat <?= $nb_attente > 0 ? 'alerte' : '' ?>"><div class="nombre"><?= $nb_attente ?></div><div class="libelle">Inscriptions en attente</div></div>
    <div class="stat"><div class="nombre"><?= $nb_gardes ?></div><div class="libelle">Gardes ce mois-ci</div></div>
    <div class="stat"><div class="nombre"><?= $nb_op ?></div><div class="libelle">OP générés</div></div>
</div>

<div class="carte">
    <h2 style="margin-top:0">Dernières actions</h2>
    <div class="table-scroll">
    <table>
        <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($dernieres as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['date_action']) ?></td>
                <td><?= htmlspecialchars($h['username']) ?></td>
                <td><?= htmlspecialchars($h['description']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($dernieres)): ?><tr><td colspan="3">Aucune action enregistrée.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    <p><a href="historique.php">Voir tout l'historique →</a></p>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
