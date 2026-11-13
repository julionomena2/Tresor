<?php
/**
 * admin/pointages.php  (CÔTÉ ADMINISTRATEUR)
 * Vue complète des gardes avec filtres. L'admin peut modifier ou supprimer
 * n'importe quelle garde ; les suppressions sont conservées dans l'historique
 * (avec toutes les valeurs de la garde supprimée).
 */
require_once __DIR__ . '/../includes/auth.php';
exiger_connexion(['admin']);

$pdo = get_pdo();
$message = '';
if (isset($_GET['modif'])) $message = 'Garde modifiée.';

// ---- Suppression d'une garde ---------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer_pointage') {
    $id = (int) ($_POST['id'] ?? 0);
    $avant = get_pointage($id);
    if ($avant) {
        $agent = get_agent($avant['matricule']);
        $pdo->prepare('DELETE FROM pointages WHERE id = :id')->execute(['id' => $id]);
        journaliser('suppression_pointage', 'pointages', (string) $id,
            "Garde n°$id supprimée ({$avant['matricule']} — " . ($agent['nom_prenoms'] ?? '?') . ", {$avant['date_debut']})",
            $avant, null);
        $message = 'Garde supprimée.';
    }
}

// ---- Filtres (GET) ---------------------------------------------------
$f_matricule = trim($_GET['matricule'] ?? '');
$f_du = date_valide($_GET['du'] ?? '') ? $_GET['du'] : '';
$f_au = date_valide($_GET['au'] ?? '') ? $_GET['au'] : '';

$pointages = get_pointages(['matricule' => $f_matricule, 'du' => $f_du, 'au' => $f_au], 300);
$agents = get_tous_agents();

$titre_page = 'Pointages';
require __DIR__ . '/../includes/header.php';
?>

<h1>Tous les pointages</h1>

<?php if ($message): ?><div class="message-succes"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<div class="carte">
    <form method="get" class="form-inline">
        <div class="form-ligne">
            <label for="matricule">Agent</label>
            <select id="matricule" name="matricule">
                <option value="">Tous</option>
                <?php foreach ($agents as $a): ?>
                    <option value="<?= htmlspecialchars($a['matricule']) ?>" <?= $f_matricule === $a['matricule'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['matricule'] . ' — ' . $a['nom_prenoms']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-ligne"><label for="du">Du</label><input type="date" id="du" name="du" value="<?= htmlspecialchars($f_du) ?>"></div>
        <div class="form-ligne"><label for="au">Au</label><input type="date" id="au" name="au" value="<?= htmlspecialchars($f_au) ?>"></div>
        <div class="form-ligne">
            <button type="submit">Filtrer</button>
            <a class="btn btn-secondaire" href="pointages.php">Réinitialiser</a>
        </div>
    </form>
</div>

<div class="carte">
    <p><?= count($pointages) ?> garde(s) affichée(s) (300 maximum).</p>
    <div class="table-scroll">
    <table>
        <thead><tr><th>N°</th><th>Agent</th><th>Début</th><th>Fin</th><th>Montant</th><th>Saisi par</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($pointages as $p): ?>
            <tr>
                <td><?= (int) $p['id'] ?></td>
                <td><?= htmlspecialchars($p['matricule'] . ' — ' . $p['nom_prenoms']) ?></td>
                <td><?= htmlspecialchars($p['date_debut']) ?> à <?= htmlspecialchars(substr($p['heure_debut'], 0, 5)) ?></td>
                <td><?= htmlspecialchars($p['date_fin']) ?> à <?= htmlspecialchars(substr($p['heure_fin'], 0, 5)) ?></td>
                <td><?= number_format((float) $p['montant'], 0, ',', ' ') ?> Ar</td>
                <td><?= htmlspecialchars($p['saisi_par_username'] ?? '—') ?></td>
                <td>
                    <a class="btn btn-secondaire petit" href="../user/pointage_edit.php?id=<?= (int) $p['id'] ?>&retour=admin">Modifier</a>
                    <form method="post" class="form-inline-compact" onsubmit="return confirm('Supprimer cette garde ?');">
                        <input type="hidden" name="action" value="supprimer_pointage">
                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <button type="submit" class="petit btn-danger">Supprimer</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($pointages)): ?><tr><td colspan="7">Aucune garde trouvée.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
