<?php
/**
 * user/pointage.php  (CÔTÉ UTILISATEUR)
 * -------------------------------------------------------------
 * Saisie des gardes de 24h + liste des dernières gardes.
 * Accessible aux utilisateurs ET à l'admin.
 * Chaque ajout est enregistré dans l'historique.
 * -------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth.php';
exiger_connexion(); // utilisateur ou admin
$utilisateur = utilisateur_courant();

$message = '';
$erreur = '';

// Messages après redirection (évite le double envoi du formulaire au rechargement)
if (isset($_GET['ok']))    $message = 'Garde de 24h enregistrée.';
if (isset($_GET['modif'])) $message = 'Garde modifiée.';

// -----------------------------------------------------------------
// Enregistrement d'une nouvelle garde
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajouter_pointage') {
    $matricule   = trim($_POST['matricule'] ?? '');
    $date_debut  = trim($_POST['date_debut'] ?? '');
    $heure_debut = trim($_POST['heure_debut'] ?? '');
    $reference   = trim($_POST['reference_om'] ?? '') ?: null;

    $agent = $matricule !== '' ? get_agent($matricule) : null;

    if (!$agent || $agent['statut'] !== 'actif') {
        $erreur = 'Veuillez sélectionner un agent actif.';
    } elseif (!date_valide($date_debut) || !heure_valide($heure_debut)) {
        $erreur = 'Date ou heure de début invalide.';
    } else {
        $id = creer_pointage($matricule, $date_debut, $heure_debut, $reference, (int) $utilisateur['id']);

        // Trace dans l'historique (valeurs complètes de la garde créée)
        journaliser('ajout_pointage', 'pointages', (string) $id,
            "Garde de 24h ajoutée pour {$agent['matricule']} ({$agent['nom_prenoms']}) le $date_debut à $heure_debut",
            null, get_pointage($id));

        header('Location: pointage.php?ok=1');
        exit;
    }
}

// Pré-remplissage : l'agent entrant prend la relève à l'heure de fin du dernier pointage
$dernier_pointage = get_dernier_pointage_global();
$suggestion_date  = $dernier_pointage ? $dernier_pointage['date_fin'] : date('Y-m-d');
$suggestion_heure = $dernier_pointage ? substr($dernier_pointage['heure_fin'], 0, 5) : '08:00';

$agents = get_tous_agents();
$pointages_recents = get_pointages_recents(30);

$titre_page = 'Pointage';
require __DIR__ . '/../includes/header.php';
?>

<h1>Pointage des gardes</h1>

<?php if ($message): ?><div class="message-succes"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur): ?><div class="message-erreur"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<div class="carte">
    <h2 style="margin-top:0">Enregistrer une garde (24h)</h2>

    <?php if ($dernier_pointage): ?>
        <div class="info-box">
            Dernière relève prévue le <strong><?= htmlspecialchars($dernier_pointage['date_fin']) ?></strong>
            à <strong><?= htmlspecialchars(substr($dernier_pointage['heure_fin'], 0, 5)) ?></strong>.
            La date et l'heure de début sont pré-remplies en conséquence (modifiables).
        </div>
    <?php endif; ?>

    <form method="post" class="form-inline">
        <input type="hidden" name="action" value="ajouter_pointage">

        <div class="form-ligne">
            <label for="matricule">Agent</label>
            <select id="matricule" name="matricule" required>
                <option value="">-- Choisir un agent --</option>
                <?php foreach ($agents as $a): if ($a['statut'] !== 'actif') continue; ?>
                    <option value="<?= htmlspecialchars($a['matricule']) ?>">
                        <?= htmlspecialchars($a['matricule'] . ' — ' . $a['nom_prenoms']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-ligne">
            <label for="date_debut">Date de début</label>
            <input type="date" id="date_debut" name="date_debut" value="<?= htmlspecialchars($suggestion_date) ?>" required>
        </div>

        <div class="form-ligne">
            <label for="heure_debut">Heure de début</label>
            <input type="time" id="heure_debut" name="heure_debut" value="<?= htmlspecialchars($suggestion_heure) ?>" required>
        </div>

        <div class="form-ligne">
            <label for="reference_om">Référence OM/BS (optionnel)</label>
            <input type="text" id="reference_om" name="reference_om" maxlength="100">
        </div>

        <div class="form-ligne">
            <button type="submit">Enregistrer la garde</button>
        </div>
    </form>
    <p style="font-size:13px; color:#64748b;">
        La fin est calculée automatiquement (+ 24h exactement). Toute saisie ou modification est enregistrée dans l'historique.
    </p>
</div>

<div class="carte">
    <h2 style="margin-top:0">Dernières gardes enregistrées</h2>
    <div class="table-scroll">
    <table>
        <thead>
            <tr><th>Agent</th><th>Début</th><th>Fin</th><th>Saisi par</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($pointages_recents as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['matricule'] . ' — ' . $p['nom_prenoms']) ?></td>
                <td><?= htmlspecialchars($p['date_debut']) ?> à <?= htmlspecialchars(substr($p['heure_debut'], 0, 5)) ?></td>
                <td><?= htmlspecialchars($p['date_fin']) ?> à <?= htmlspecialchars(substr($p['heure_fin'], 0, 5)) ?></td>
                <td><?= htmlspecialchars($p['saisi_par_username'] ?? '—') ?></td>
                <td>
                    <?php // Un utilisateur ne peut modifier que SES saisies ; l'admin peut tout modifier ?>
                    <?php if ($utilisateur['role'] === 'admin' || (int) $p['saisi_par'] === (int) $utilisateur['id']): ?>
                        <a class="btn btn-secondaire petit" href="pointage_edit.php?id=<?= (int) $p['id'] ?>">Modifier</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($pointages_recents)): ?>
            <tr><td colspan="5">Aucune garde enregistrée pour le moment.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
