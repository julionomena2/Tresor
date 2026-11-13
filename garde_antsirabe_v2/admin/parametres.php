<?php
/**
 * admin/parametres.php  (CÔTÉ ADMINISTRATEUR)
 * Modification des constantes du modèle « OP à imprimer » (mission, exercice,
 * programme, lieu, indemnité par garde, etc.). Tout changement est journalisé.
 */
require_once __DIR__ . '/../includes/auth.php';
exiger_connexion(['admin']);

$pdo = get_pdo();
$message = '';
$erreur = '';

// Libellés lisibles pour chaque clé
$libelles = [
    'ministere' => 'Ministère', 'ministere_code' => 'Code ministère', 'republique' => 'République',
    'ordonnateur' => 'Ordonnateur', 'commune_beneficiaire' => 'Commune bénéficiaire', 'mission' => 'Mission',
    'exercice' => 'Exercice (année)', 'budget' => 'Budget', 'categorie' => 'Catégorie', 'code_cf' => 'Code CF',
    'tp_assignataire' => 'TP assignataire', 'texte_credits' => 'Texte « En vertu des crédits… »',
    'programme' => 'Programme', 'activite_projet' => 'Activité / Projet',
    'imputation_administrative' => 'Imputation administrative', 'compte' => 'Compte',
    'sect_conv' => 'SECT-CONV', 'financement' => 'Financement', 'poste_comptable_nom' => 'Poste comptable (nom)',
    'poste_comptable_code' => 'Code poste (N° OP)', 'lieu' => 'Lieu (« Fait à … »)',
    'indemnite_par_garde' => 'Indemnité par garde de 24h (Ar)', 'taxes' => 'Taxes (Ar)', 'a_precompter' => 'À précompter (Ar)',
];
$cles_numeriques = ['indemnite_par_garde', 'taxes', 'a_precompter'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actuels = get_tous_parametres();
    $saisis  = $_POST['param'] ?? [];
    $avant = [];
    $apres = [];

    foreach ($actuels as $cle => $ancienne) {
        if (!isset($saisis[$cle])) continue;
        $nouvelle = str_replace("\r\n", "\n", trim((string) $saisis[$cle]));
        if ($nouvelle === $ancienne) continue;

        if (in_array($cle, $cles_numeriques, true) && !is_numeric($nouvelle)) {
            $erreur = 'La valeur de « ' . ($libelles[$cle] ?? $cle) . ' » doit être un nombre.';
            break;
        }
        if ($nouvelle === '') {
            $erreur = 'La valeur de « ' . ($libelles[$cle] ?? $cle) . ' » ne peut pas être vide.';
            break;
        }
        $avant[$cle] = $ancienne;
        $apres[$cle] = $nouvelle;
    }

    if ($erreur === '' && !empty($apres)) {
        $stmt = $pdo->prepare('UPDATE parametres SET valeur = :v WHERE cle = :c');
        foreach ($apres as $cle => $valeur) {
            $stmt->execute(['v' => $valeur, 'c' => $cle]);
        }
        journaliser('modification_parametres', 'parametres', implode(',', array_keys($apres)),
            'Paramètres modifiés : ' . implode(', ', array_keys($apres)), $avant, $apres);
        $message = count($apres) . ' paramètre(s) mis à jour.';
    } elseif ($erreur === '') {
        $message = 'Aucun changement.';
    }
}

$parametres = get_tous_parametres();
$titre_page = 'Paramètres';
require __DIR__ . '/../includes/header.php';
?>

<h1>Paramètres du document « OP à imprimer »</h1>
<div class="info-box">
    Ces valeurs sont les constantes fixes du modèle PDF (tout ce qui n'est pas en jaune dans le fichier Excel).
    Modifiez-les seulement si elles changent (ex : nouvel exercice).
    Le changement de l'<strong>indemnité par garde</strong> ne s'applique qu'aux <strong>nouvelles</strong> gardes saisies :
    les gardes déjà enregistrées gardent leur montant.
</div>

<?php if ($message): ?><div class="message-succes"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur): ?><div class="message-erreur"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<div class="carte">
    <form method="post">
        <?php foreach ($parametres as $cle => $valeur): ?>
            <div class="form-ligne">
                <label for="p-<?= htmlspecialchars($cle) ?>"><?= htmlspecialchars($libelles[$cle] ?? $cle) ?></label>
                <?php if (strpos($valeur, "\n") !== false || strlen($valeur) > 60): ?>
                    <textarea id="p-<?= htmlspecialchars($cle) ?>" name="param[<?= htmlspecialchars($cle) ?>]" rows="3"><?= htmlspecialchars($valeur) ?></textarea>
                <?php else: ?>
                    <input type="text" id="p-<?= htmlspecialchars($cle) ?>" name="param[<?= htmlspecialchars($cle) ?>]" value="<?= htmlspecialchars($valeur) ?>">
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <button type="submit">Enregistrer les paramètres</button>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
