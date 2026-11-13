<?php
/**
 * admin/generer_pdf_tous.php  (CÔTÉ ADMINISTRATEUR)
 * Génère un PDF par agent actif ayant au moins une garde sur la période,
 * puis les regroupe dans un ZIP. Chaque OP généré est journalisé.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../templates/op_template.php';
exiger_connexion(['admin']);
$admin = utilisateur_courant();

require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$periode_debut = $_GET['periode_debut'] ?? '';
$periode_fin   = $_GET['periode_fin'] ?? '';

if (!date_valide($periode_debut) || !date_valide($periode_fin) || $periode_debut > $periode_fin) {
    die('Période invalide. <a href="calcul_pdf.php">Retour</a>');
}

$annee  = date('Y', strtotime($periode_fin));
$agents = get_tous_agents();
$params = get_tous_parametres();

$dossier_tmp = sys_get_temp_dir() . '/op_' . uniqid();
mkdir($dossier_tmp);

$options = new Options();
$options->set('isRemoteEnabled', false);
$nb_generes = 0;

foreach ($agents as $agent) {
    if ($agent['statut'] !== 'actif') continue;

    $calcul = calculer_indemnite_periode($agent['matricule'], $periode_debut, $periode_fin);
    if ($calcul['nb_gardes'] === 0) continue; // pas de garde sur la période : pas de PDF

    $numero_op = generer_numero_op($annee);

    $html = generer_html_op([
        'numero_op'       => $numero_op,
        'matricule'       => $agent['matricule'],
        'nom_agent'       => $agent['nom_prenoms'],
        'montant'         => $calcul['montant_total'],
        'montant_lettres' => nombre_en_lettres($calcul['montant_total']),
        'mois_debut'      => mois_nom_fr($periode_debut),
        'mois_fin'        => mois_nom_fr($periode_fin),
        'annee'           => $annee,
        'periode_debut'   => $periode_debut,
        'periode_fin'     => $periode_fin,
        'params'          => $params,
    ]);

    enregistrer_ordre_paiement($numero_op, $agent['matricule'], $periode_debut, $periode_fin, $calcul['nb_gardes'], $calcul['montant_total'], (int) $admin['id']);
    journaliser('generation_op', 'ordres_paiement', $numero_op,
        "OP $numero_op généré (lot « tous les agents ») pour {$agent['matricule']} ({$agent['nom_prenoms']}) : {$calcul['nb_gardes']} garde(s), "
        . number_format($calcul['montant_total'], 0, ',', ' ') . " Ar, période $periode_debut au $periode_fin",
        null, ['numero_op' => $numero_op, 'matricule' => $agent['matricule'], 'periode_debut' => $periode_debut,
               'periode_fin' => $periode_fin, 'nb_gardes' => $calcul['nb_gardes'], 'montant' => $calcul['montant_total']]);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    file_put_contents($dossier_tmp . '/OP_' . str_replace(['/', ' '], '-', $numero_op) . '_' . $agent['matricule'] . '.pdf', $dompdf->output());
    $nb_generes++;
}

if ($nb_generes === 0) {
    rmdir($dossier_tmp);
    die('Aucun agent n\'a de garde enregistrée sur cette période : aucun PDF généré. <a href="calcul_pdf.php">Retour</a>');
}

$chemin_zip = sys_get_temp_dir() . '/OP_' . uniqid() . '.zip';
$zip = new ZipArchive();
$zip->open($chemin_zip, ZipArchive::CREATE);
foreach (glob($dossier_tmp . '/*.pdf') as $f) {
    $zip->addFile($f, basename($f));
}
$zip->close();

foreach (glob($dossier_tmp . '/*.pdf') as $f) unlink($f);
rmdir($dossier_tmp);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="OP_tous_agents_' . $periode_debut . '_au_' . $periode_fin . '.zip"');
header('Content-Length: ' . filesize($chemin_zip));
readfile($chemin_zip);
unlink($chemin_zip);
exit;
