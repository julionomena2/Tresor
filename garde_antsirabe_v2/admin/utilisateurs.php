<?php
/**
 * admin/utilisateurs.php  (CÔTÉ ADMINISTRATEUR)
 * -------------------------------------------------------------
 * Gestion des comptes utilisateurs :
 *   - valider / refuser une inscription
 *   - suspendre / réactiver un compte
 *   - réinitialiser un mot de passe
 *   - supprimer un compte
 * Toutes les décisions sont enregistrées dans l'historique.
 * -------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth.php';
exiger_connexion(['admin']);
$admin = utilisateur_courant();
$pdo = get_pdo();

$message = '';
$erreur = '';

/** Charge un compte de rôle "utilisateur" (l'admin n'est jamais modifiable ici). */
function charger_cible(int $id): ?array
{
    $stmt = get_pdo()->prepare(
        "SELECT id, username, nom_complet, role, statut, date_inscription, date_validation
           FROM utilisateurs WHERE id = :id AND role = 'utilisateur'"
    );
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
}

// Transitions de statut autorisées : action => [statuts de départ, nouveau statut, code historique, texte]
$transitions = [
    'valider'   => ['depuis' => ['en_attente'],            'vers' => 'actif',    'log' => 'validation_utilisateur',   'texte' => 'validé'],
    'refuser'   => ['depuis' => ['en_attente'],            'vers' => 'refuse',   'log' => 'refus_utilisateur',        'texte' => 'refusé'],
    'suspendre' => ['depuis' => ['actif'],                 'vers' => 'suspendu', 'log' => 'suspension_utilisateur',   'texte' => 'suspendu'],
    'reactiver' => ['depuis' => ['suspendu', 'refuse'],    'vers' => 'actif',    'log' => 'reactivation_utilisateur', 'texte' => 'réactivé'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $cible  = charger_cible((int) ($_POST['id'] ?? 0));

    if (!$cible) {
        $erreur = 'Utilisateur introuvable.';

    } elseif (isset($transitions[$action])) {
        $t = $transitions[$action];
        if (!in_array($cible['statut'], $t['depuis'], true)) {
            $erreur = 'Action impossible dans l\'état actuel du compte.';
        } else {
            // Une activation enregistre aussi la date de validation
            $sql = $t['vers'] === 'actif'
                ? 'UPDATE utilisateurs SET statut = :s, date_validation = NOW(), valide_par = :a WHERE id = :id'
                : 'UPDATE utilisateurs SET statut = :s, valide_par = :a WHERE id = :id';
            $pdo->prepare($sql)->execute(['s' => $t['vers'], 'a' => $admin['id'], 'id' => $cible['id']]);

            journaliser($t['log'], 'utilisateurs', (string) $cible['id'],
                "Compte « {$cible['username']} » ({$cible['nom_complet']}) {$t['texte']}",
                ['statut' => $cible['statut']], ['statut' => $t['vers']]);
            $message = "Compte « {$cible['username']} » {$t['texte']}.";
        }

    } elseif ($action === 'reset_mdp') {
        $nouveau = $_POST['nouveau_mdp'] ?? '';
        if (strlen($nouveau) < 8) {
            $erreur = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
        } else {
            $pdo->prepare('UPDATE utilisateurs SET password = :p WHERE id = :id')
                ->execute(['p' => password_hash($nouveau, PASSWORD_BCRYPT), 'id' => $cible['id']]);
            // On journalise l'événement, JAMAIS le mot de passe
            journaliser('reinitialisation_mdp', 'utilisateurs', (string) $cible['id'],
                "Mot de passe de « {$cible['username']} » réinitialisé par l'administrateur");
            $message = "Mot de passe de « {$cible['username']} » réinitialisé. Communiquez-le lui de manière sûre.";
        }

    } elseif ($action === 'supprimer') {
        $pdo->prepare('DELETE FROM utilisateurs WHERE id = :id')->execute(['id' => $cible['id']]);
        journaliser('suppression_utilisateur', 'utilisateurs', (string) $cible['id'],
            "Compte « {$cible['username']} » ({$cible['nom_complet']}) supprimé", $cible, null);
        $message = "Compte « {$cible['username']} » supprimé.";
    }
}

// Liste : inscriptions en attente en premier
$utilisateurs = $pdo->query(
    "SELECT * FROM utilisateurs WHERE role = 'utilisateur'
      ORDER BY FIELD(statut, 'en_attente', 'actif', 'suspendu', 'refuse'), date_inscription DESC"
)->fetchAll();

$libelles_statut = ['en_attente' => 'En attente', 'actif' => 'Actif', 'refuse' => 'Refusé', 'suspendu' => 'Suspendu'];
$classes_statut  = ['en_attente' => 'badge-attente', 'actif' => 'badge-actif', 'refuse' => 'badge-refuse', 'suspendu' => 'badge-suspendu'];

$titre_page = 'Utilisateurs';
require __DIR__ . '/../includes/header.php';
?>

<h1>Gestion des utilisateurs</h1>
<p>Les utilisateurs s'inscrivent eux-mêmes depuis la page de connexion ; ils ne peuvent se connecter qu'une fois leur compte validé ici.</p>

<?php if ($message): ?><div class="message-succes"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur): ?><div class="message-erreur"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<div class="carte">
    <div class="table-scroll">
    <table>
        <thead>
            <tr><th>Nom complet</th><th>Identifiant</th><th>Statut</th><th>Inscrit le</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($utilisateurs as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['nom_complet']) ?></td>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><span class="badge <?= $classes_statut[$u['statut']] ?>"><?= $libelles_statut[$u['statut']] ?></span></td>
                <td><?= htmlspecialchars($u['date_inscription']) ?></td>
                <td>
                    <?php
                    // Petit générateur de bouton d'action (formulaire POST)
                    $bouton = function (string $action, string $libelle, string $classe = '', string $confirm = '') use ($u) {
                        $onsubmit = $confirm !== '' ? ' onsubmit="return confirm(\'' . htmlspecialchars($confirm, ENT_QUOTES) . '\');"' : '';
                        echo '<form method="post" class="form-inline-compact"' . $onsubmit . '>'
                           . '<input type="hidden" name="id" value="' . (int) $u['id'] . '">'
                           . '<input type="hidden" name="action" value="' . $action . '">'
                           . '<button type="submit" class="petit ' . $classe . '">' . $libelle . '</button></form> ';
                    };

                    if ($u['statut'] === 'en_attente') {
                        $bouton('valider', 'Valider', 'btn-succes');
                        $bouton('refuser', 'Refuser', 'btn-danger', 'Refuser cette inscription ?');
                    } elseif ($u['statut'] === 'actif') {
                        $bouton('suspendre', 'Suspendre', 'btn-secondaire', 'Suspendre ce compte ? Il sera déconnecté.');
                    } else {
                        $bouton('reactiver', 'Réactiver', 'btn-succes');
                    }
                    ?>
                    <form method="post" class="form-inline-compact">
                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <input type="hidden" name="action" value="reset_mdp">
                        <input type="text" name="nouveau_mdp" placeholder="Nouveau mot de passe" minlength="8" autocomplete="off" required>
                        <button type="submit" class="petit btn-secondaire">Réinitialiser</button>
                    </form>
                    <?php $bouton('supprimer', 'Supprimer', 'btn-danger', 'Supprimer définitivement ce compte ?'); ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($utilisateurs)): ?>
            <tr><td colspan="5">Aucun utilisateur inscrit pour le moment.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
