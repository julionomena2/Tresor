<?php
/**
 * user/pointage_edit.php
 * -------------------------------------------------------------
 * Modification d'une garde.
 *  - un utilisateur ne peut modifier QUE les gardes qu'il a saisies
 *  - l'admin peut modifier toutes les gardes
 * Les champs réellement modifiés sont enregistrés (avant/après) dans l'historique.
 * -------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth.php';
exiger_connexion();
$utilisateur = utilisateur_courant();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$pointage = get_pointage($id);
if (!$pointage) {
    die('Garde introuvable. <a href="pointage.php">Retour</a>');
}

// Contrôle des droits
$est_admin = $utilisateur['role'] === 'admin';
if (!$est_admin && (int) $pointage['saisi_par'] !== (int) $utilisateur['id']) {
    http_response_code(403);
    die('Accès refusé : vous ne pouvez modifier que vos propres saisies. <a href="pointage.php">Retour</a>');
}

// Page vers laquelle revenir (liste admin ou page de saisie)
$retour_admin = $est_admin && (($_GET['retour'] ?? $_POST['retour'] ?? '') === 'admin');
$url_retour = $retour_admin ? BASE_URL . '/admin/pointages.php' : BASE_URL . '/user/pointage.php';

$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $matricule   = trim($_POST['matricule'] ?? '');
    $date_debut  = trim($_POST['date_debut'] ?? '');
    $heure_debut = trim($_POST['heure_debut'] ?? '');
    $reference   = trim($_POST['reference_om'] ?? '') ?: null;

    $agent = $matricule !== '' ? get_agent($matricule) : null;

    if (!$agent) {
        $erreur = 'Agent introuvable.';
    } elseif (!date_valide($date_debut) || !heure_valide($heure_debut)) {
        $erreur = 'Date ou heure de début invalide.';
    } else {
        modifier_pointage($id, $matricule, $date_debut, $heure_debut, $reference);

        // Compare avant/après : on ne journalise QUE ce qui a changé
        $apres = get_pointage($id);
        $diff  = champs_modifies($pointage, $apres);

        if (!empty($diff['apres'])) {
            journaliser('modification_pointage', 'pointages', (string) $id,
                "Garde n°$id modifiée (agent {$agent['matricule']} — {$agent['nom_prenoms']})",
                $diff['avant'], $diff['apres']);
        }
        header('Location: ' . $url_retour . '?modif=1');
        exit;
    }
}

$agents = get_tous_agents();
$titre_page = 'Modifier une garde';
require __DIR__ . '/../includes/header.php';
?>

<h1>Modifier la garde n°<?= (int) $pointage['id'] ?></h1>

<?php if ($erreur): ?><div class="message-erreur"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<div class="carte">
    <form method="post" class="form-inline">
        <input type="hidden" name="id" value="<?= (int) $pointage['id'] ?>">
        <?php if ($retour_admin): ?><input type="hidden" name="retour" value="admin"><?php endif; ?>

        <div class="form-ligne">
            <label for="matricule">Agent</label>
            <select id="matricule" name="matricule" required>
                <?php foreach ($agents as $a): ?>
                    <option value="<?= htmlspecialchars($a['matricule']) ?>" <?= $a['matricule'] === $pointage['matricule'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['matricule'] . ' — ' . $a['nom_prenoms']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-ligne">
            <label for="date_debut">Date de début</label>
            <input type="date" id="date_debut" name="date_debut" value="<?= htmlspecialchars($pointage['date_debut']) ?>" required>
        </div>
        <div class="form-ligne">
            <label for="heure_debut">Heure de début</label>
            <input type="time" id="heure_debut" name="heure_debut" value="<?= htmlspecialchars(substr($pointage['heure_debut'], 0, 5)) ?>" required>
        </div>
        <div class="form-ligne">
            <label for="reference_om">Référence OM/BS</label>
            <input type="text" id="reference_om" name="reference_om" maxlength="100" value="<?= htmlspecialchars($pointage['reference_om'] ?? '') ?>">
        </div>
        <div class="form-ligne">
            <button type="submit">Enregistrer</button>
            <a class="btn btn-secondaire" href="<?= htmlspecialchars($url_retour) ?>">Annuler</a>
        </div>
    </form>
    <p style="font-size:13px; color:#64748b;">La fin est recalculée automatiquement (début + 24h). La modification sera enregistrée dans l'historique.</p>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
