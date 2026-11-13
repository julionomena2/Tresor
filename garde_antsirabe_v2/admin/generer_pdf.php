<?php
/**
 * admin/generer_pdf.php  (CÔTÉ ADMINISTRATEUR)
 * Génère le PDF "Ordre de Paiement" d'UN agent sur une période (GET :
 * matricule, periode_debut, periode_fin). La génération est journalisée.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../templates/op_template.php';
exiger_connexion(['admin']);
$admin = utilisateur_courant();

require_once __DIR__ . '/../vendor/autoload.php'; // Dompdf (installé via Composer)

use Dompdf\Dompdf;
use Dompdf\Options;

$matricule     = trim($_GET['matricule'] ?? '');
$periode_debut = $_GET['periode_debut'] ?? '';
$periode_fin   = $_GET['periode_fin'] ?? '';

if ($matricule === '' || !date_valide($periode_debut) || !date_valide($periode_fin) || $periode_debut > $periode_fin) {
    die('Paramètres invalides (agent / période). <a href="calcul_pdf.php">Retour</a>');
}

$agent = get_agent($matricule);
if (!$agent) {
    die('Agent introuvable. <a href="calcul_pdf.php">Retour</a>');
}

$calcul = calculer_indemnite_periode($matricule, $periode_debut, $periode_fin);
if ($calcul['nb_gardes'] === 0) {
    die('Aucune garde enregistrée pour cet agent sur cette période : PDF non généré. <a href="calcul_pdf.php">Retour</a>');
}

$annee = date('Y', strtotime($periode_fin));
$numero_op = generer_numero_op($annee);

$donnees = [
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
    'params'          => get_tous_parametres(),
];
$html = generer_html_op($donnees);

// Enregistre l'OP + trace dans l'historique AVANT l'envoi du PDF
enregistrer_ordre_paiement($numero_op, $matricule, $periode_debut, $periode_fin, $calcul['nb_gardes'], $calcul['montant_total'], (int) $admin['id']);
journaliser('generation_op', 'ordres_paiement', $numero_op,
    "OP $numero_op généré pour {$agent['matricule']} ({$agent['nom_prenoms']}) : {$calcul['nb_gardes']} garde(s), "
    . number_format($calcul['montant_total'], 0, ',', ' ') . " Ar, période $periode_debut au $periode_fin",
    null, ['numero_op' => $numero_op, 'matricule' => $matricule, 'periode_debut' => $periode_debut,
           'periode_fin' => $periode_fin, 'nb_gardes' => $calcul['nb_gardes'], 'montant' => $calcul['montant_total']]);

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$nom_fichier = 'OP_' . str_replace(['/', ' '], '-', $numero_op) . '_' . $matricule . '.pdf';
$dompdf->stream($nom_fichier, ['Attachment' => true]);
