<?php
/**
 * admin/agents.php  (CÔTÉ ADMINISTRATEUR)
 * -------------------------------------------------------------
 * Gestion des données : ajouter / modifier / supprimer un agent.
 * Chaque changement est enregistré dans l'historique (avant/après).
 * -------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth.php';
exiger_connexion(['admin']);

$pdo = get_pdo();
$message = '';
$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- AJOUT -------------------------------------------------------
    if ($action === 'ajouter') {
        $matricule   = trim($_POST['matricule'] ?? '');
        $nom_prenoms = trim($_POST['nom_prenoms'] ?? '');

        if ($matricule === '' || $nom_prenoms === '') {
            $erreur = 'Le matricule et le nom sont obligatoires.';
        } elseif (get_agent($matricule)) {
            $erreur = 'Ce matricule existe déjà.';
        } else {
            $pdo->prepare('INSERT INTO agents (matricule, nom_prenoms) VALUES (:m, :n)')
                ->execute(['m' => $matricule, 'n' => $nom_prenoms]);
            journaliser('ajout_agent', 'agents', $matricule,
                "Agent ajouté : $matricule — $nom_prenoms", null, get_agent($matricule));
            $message = 'Agent ajouté avec succès.';
        }
    }

    // ---- MODIFICATION ------------------------------------------------
    if ($action === 'modifier') {
        $matricule   = $_POST['matricule_original'] ?? '';
        $nom_prenoms = trim($_POST['nom_prenoms'] ?? '');
        $statut      = ($_POST['statut'] ?? 'actif') === 'inactif' ? 'inactif' : 'actif';
        $avant       = get_agent($matricule);

        if (!$avant || $nom_prenoms === '') {
            $erreur = 'Agent introuvable ou nom vide.';
        } else {
            $pdo->prepare('UPDATE agents SET nom_prenoms = :n, statut = :s WHERE matricule = :m')
                ->execute(['n' => $nom_prenoms, 's' => $statut, 'm' => $matricule]);

            $diff = champs_modifies($avant, get_agent($matricule));
            if (!empty($diff['apres'])) {
                journaliser('modification_agent', 'agents', $matricule,
                    "Agent $matricule ({$avant['nom_prenoms']}) modifié", $diff['avant'], $diff['apres']);
                $message = 'Agent mis à jour.';
            } else {
                $message = 'Aucun changement.';
            }
        }
    }

    // ---- SUPPRESSION -------------------------------------------------
    if ($action === 'supprimer') {
        $matricule = $_POST['matricule'] ?? '';
        $avant = get_agent($matricule);
        if ($avant) {
            // On compte ses gardes avant suppression : elles seront supprimées en cascade
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM pointages WHERE matricule = :m');
            $stmt->execute(['m' => $matricule]);
            $nb_gardes = (int) $stmt->fetchColumn();

            $pdo->prepare('DELETE FROM agents WHERE matricule = :m')->execute(['m' => $matricule]);

            $avant['nb_gardes_supprimees'] = $nb_gardes;
            journaliser('suppression_agent', 'agents', $matricule,
                "Agent supprimé : $matricule — {$avant['nom_prenoms']} ($nb_gardes garde(s) supprimée(s) avec lui)", $avant, null);
            $message = 'Agent supprimé.';
        }
    }
}

// ---- Recherche d'un agent par son identifiant (matricule) -----------
$terme_recherche = trim($_GET['recherche'] ?? $_GET['q'] ?? $_GET['identifiant'] ?? $_GET['matricule'] ?? '');
$agents = $terme_recherche !== ''
    ? rechercher_agents_par_identifiant($terme_recherche)
    : get_tous_agents();
$agent_trouve = $terme_recherche !== '' ? rechercher_agent_par_identifiant($terme_recherche) : null;

$titre_page = 'Agents';
require __DIR__ . '/../includes/header.php';
?>

<h1>Gestion des agents</h1>
<p>Liste des agents de police pouvant effectuer des gardes (feuille « IDENTIFICATION » du fichier Excel).</p>

<?php if ($message): ?><div class="message-succes"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur): ?><div class="message-erreur"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<div class="carte">
    <h2 style="margin-top:0">Ajouter un agent</h2>
    <form method="post" class="form-inline">
        <input type="hidden" name="action" value="ajouter">
        <div class="form-ligne">
            <label for="matricule">Matricule</label>
            <input type="text" id="matricule" name="matricule" placeholder="ex: 391.082" maxlength="20" required>
        </div>
        <div class="form-ligne">
            <label for="nom_prenoms">Nom et prénoms</label>
            <input type="text" id="nom_prenoms" name="nom_prenoms" placeholder="ex: RAKOTO Jean" maxlength="150" required>
        </div>
        <div class="form-ligne"><button type="submit">Ajouter</button></div>
    </form>
</div>

<?php if ($agent_trouve): ?>
<div class="info-box">
    <strong>Agent trouvé :</strong>
    <?= htmlspecialchars($agent_trouve['matricule']) ?> — <?= htmlspecialchars($agent_trouve['nom_prenoms']) ?>
    (statut : <?= htmlspecialchars($agent_trouve['statut']) ?>)
</div>
<?php elseif ($terme_recherche !== '' && empty($agents)): ?>
<div class="message-erreur">Aucun agent ne correspond à l'identifiant « <?= htmlspecialchars($terme_recherche) ?> ».</div>
<?php endif; ?>

<div class="carte">
    <h2 style="margin-top:0">Rechercher un agent par son identifiant</h2>
    <form method="get" class="form-inline">
        <div class="form-ligne">
            <label for="recherche">Identifiant (matricule)</label>
            <input type="text" id="recherche" name="recherche" value="<?= htmlspecialchars($terme_recherche) ?>"
                   placeholder="ex: 391.082" maxlength="20">
        </div>
        <div class="form-ligne">
            <button type="submit">Rechercher</button>
            <a class="btn btn-secondaire" href="agents.php">Réinitialiser</a>
        </div>
    </form>
</div>

<div class="carte">
    <h2 style="margin-top:0">Liste des agents (<?= count($agents) ?>)</h2>
    <div class="table-scroll">
    <table>
        <thead><tr><th>Matricule</th><th>Nom et prénoms</th><th>Statut</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($agents as $agent): ?>
            <tr>
                <td><?= htmlspecialchars($agent['matricule']) ?></td>
                <td>
                    <form method="post" id="f-<?= htmlspecialchars($agent['matricule']) ?>" class="form-inline-compact">
                        <input type="hidden" name="action" value="modifier">
                        <input type="hidden" name="matricule_original" value="<?= htmlspecialchars($agent['matricule']) ?>">
                        <input type="text" name="nom_prenoms" value="<?= htmlspecialchars($agent['nom_prenoms']) ?>" style="width:240px" maxlength="150">
                </td>
                <td>
                        <select name="statut">
                            <option value="actif"   <?= $agent['statut'] === 'actif' ? 'selected' : '' ?>>Actif</option>
                            <option value="inactif" <?= $agent['statut'] === 'inactif' ? 'selected' : '' ?>>Inactif</option>
                        </select>
                </td>
                <td>
                        <button type="submit" class="petit btn-secondaire">Enregistrer</button>
                    </form>
                    <form method="post" class="form-inline-compact"
                          onsubmit="return confirm('Supprimer définitivement cet agent ET toutes ses gardes ?');">
                        <input type="hidden" name="action" value="supprimer">
                        <input type="hidden" name="matricule" value="<?= htmlspecialchars($agent['matricule']) ?>">
                        <button type="submit" class="petit btn-danger">Supprimer</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($agents)): ?><tr><td colspan="4">Aucun agent enregistré.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
