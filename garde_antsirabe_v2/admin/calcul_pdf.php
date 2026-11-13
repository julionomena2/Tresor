<?php
/**
 * admin/calcul_pdf.php  (CÔTÉ ADMINISTRATEUR)
 * Calcul de l'indemnité cumulée d'un agent sur une période
 * + génération des Ordres de Paiement (PDF) : un agent ou tous.
 */
require_once __DIR__ . '/../includes/auth.php';
exiger_connexion(['admin']);

$resultat = null;
$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'calculer') {
    $matricule = trim($_POST['matricule_calc'] ?? '');
    $debut = $_POST['periode_debut'] ?? '';
    $fin   = $_POST['periode_fin'] ?? '';
    $agent = $matricule !== '' ? get_agent($matricule) : null;

    if (!$agent || !date_valide($debut) || !date_valide($fin) || $debut > $fin) {
        $erreur = 'Agent ou période invalide.';
    } else {
        $resultat = calculer_indemnite_periode($matricule, $debut, $fin);
        $resultat += ['matricule' => $matricule, 'nom_agent' => $agent['nom_prenoms'], 'debut' => $debut, 'fin' => $fin];
    }
}

$agents = get_tous_agents();
$titre_page = 'Indemnités & PDF';
require __DIR__ . '/../includes/header.php';
?>

<h1>Indemnités et Ordres de Paiement</h1>
<?php if ($erreur): ?><div class="message-erreur"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<div class="carte">
    <h2 style="margin-top:0">Calculer l'indemnité cumulée d'un agent</h2>
    <form method="post" class="form-inline">
        <input type="hidden" name="action" value="calculer">
        <div class="form-ligne">
            <label for="matricule_calc">Agent</label>
            <select id="matricule_calc" name="matricule_calc" required>
                <option value="">-- Choisir un agent --</option>
                <?php foreach ($agents as $a): ?>
                    <option value="<?= htmlspecialchars($a['matricule']) ?>" <?= ($resultat['matricule'] ?? '') === $a['matricule'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['matricule'] . ' — ' . $a['nom_prenoms']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-ligne"><label for="periode_debut">Du</label><input type="date" id="periode_debut" name="periode_debut" value="<?= htmlspecialchars($resultat['debut'] ?? '') ?>" required></div>
        <div class="form-ligne"><label for="periode_fin">Au</label><input type="date" id="periode_fin" name="periode_fin" value="<?= htmlspecialchars($resultat['fin'] ?? '') ?>" required></div>
        <div class="form-ligne"><button type="submit">Calculer</button></div>
    </form>

    <?php if ($resultat !== null): ?>
        <div class="info-box" style="margin-top:16px">
            <strong><?= htmlspecialchars($resultat['nom_agent']) ?></strong>
            du <?= htmlspecialchars($resultat['debut']) ?> au <?= htmlspecialchars($resultat['fin']) ?> :<br>
            <?= $resultat['nb_gardes'] ?> garde(s) de 24h × <?= number_format((float) get_parametre('indemnite_par_garde', '30000'), 0, ',', ' ') ?> Ar
            = <strong><?= number_format($resultat['montant_total'], 0, ',', ' ') ?> Ar</strong>
        </div>
    <?php endif; ?>
</div>

<div class="carte">
    <h2 style="margin-top:0">Générer les Ordres de Paiement (PDF)</h2>
    <p>Document basé sur le modèle « OP à imprimer ». Seuls le N° OP, le montant, l'agent et la période changent.</p>

    <h3>Un seul agent</h3>
    <form method="get" action="generer_pdf.php" class="form-inline">
        <div class="form-ligne">
            <label for="matricule_pdf">Agent</label>
            <select id="matricule_pdf" name="matricule" required>
                <option value="">-- Choisir un agent --</option>
                <?php foreach ($agents as $a): ?>
                    <option value="<?= htmlspecialchars($a['matricule']) ?>"><?= htmlspecialchars($a['matricule'] . ' — ' . $a['nom_prenoms']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-ligne"><label for="pdeb1">Période du</label><input type="date" id="pdeb1" name="periode_debut" required></div>
        <div class="form-ligne"><label for="pfin1">au</label><input type="date" id="pfin1" name="periode_fin" required></div>
        <div class="form-ligne"><button type="submit" class="btn-succes">Générer le PDF</button></div>
    </form>

    <h3>Tous les agents actifs</h3>
    <form method="get" action="generer_pdf_tous.php" class="form-inline">
        <div class="form-ligne"><label for="pdeb2">Période du</label><input type="date" id="pdeb2" name="periode_debut" required></div>
        <div class="form-ligne"><label for="pfin2">au</label><input type="date" id="pfin2" name="periode_fin" required></div>
        <div class="form-ligne"><button type="submit" class="btn-succes">Générer tous les PDF (ZIP)</button></div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
