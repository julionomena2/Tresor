<?php
/**
 * templates/op_template.php
 * -------------------------------------------------------------
 * Reconstitue en HTML/CSS la mise en page de la feuille Excel
 * "22 - OP a imprimer".
 *
 * IMPORTANT : conformement a la consigne, SEULES 4 informations
 * sont "variables" (equivalent des cellules jaunes du fichier Excel) :
 *   - $numero_op        (cellule I5)
 *   - $montant          (cellule C18)
 *   - $matricule / $nom_agent (cellule J31 + G33 recalculee)
 *   - $periode (mois debut / mois fin / annee)  (cellules B36-E36)
 *
 * Tout le reste est repris tel quel des "parametres" (constantes fixes
 * du poste comptable 45 TG Antsirabe), exactement comme dans le fichier
 * Excel d'origine (rien d'autre ne doit changer).
 *
 * Cette fonction retourne une chaine HTML complete, prete a etre
 * transformee en PDF par Dompdf.
 * -------------------------------------------------------------
 */

function generer_html_op(array $d): string
{
    // $d attendu : numero_op, matricule, nom_agent, montant, montant_lettres,
    //              mois_debut, mois_fin, annee, params (tableau des parametres fixes)
    $p = $d['params'];

    // Petite fonction locale pour ne jamais planter si un parametre manque
    $g = fn(string $cle) => htmlspecialchars($p[$cle] ?? '', ENT_QUOTES);

    $texte_credits = nl2br(htmlspecialchars($p['texte_credits'] ?? ''));
    $sect_conv     = nl2br(htmlspecialchars($p['sect_conv'] ?? ''));

    ob_start();
    ?>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; color: #000; }
            table { width: 100%; border-collapse: collapse; }
            td, th { border: 1px solid #000; padding: 4px 6px; vertical-align: top; }
            .titre-doc { text-align: center; font-size: 14px; font-weight: bold; padding: 8px; }
            .label { font-size: 8px; color: #333; text-transform: uppercase; }
            .valeur { font-size: 10px; font-weight: bold; }
            .jaune { background-color: #fff8c4; } /* rappel visuel des zones variables, comme dans Excel */
            .centre { text-align: center; }
            .droite { text-align: right; }
            .no-border { border: none; }
        </style>
    </head>
    <body>

    <table>
        <tr>
            <td style="width:35%">
                <div class="label">Ministere</div>
                <div class="valeur"><?= $g('ministere') ?> — <?= $g('ministere_code') ?></div>
                <br>
                <div class="label">Ordonnateur</div>
                <div class="valeur"><?= $g('ordonnateur') ?></div>
                <br>
                <div class="label">Commune beneficiaire</div>
                <div class="valeur"><?= $g('commune_beneficiaire') ?></div>
            </td>
            <td style="width:35%">
                <div class="titre-doc" style="border:none">REPUBLIQUE DE MADAGASCAR</div>
                <div class="centre" style="font-weight:bold; font-size:12px;">ORDRE DE PAIEMENT</div>
                <br>
                <div class="label">Code CF</div>
                <div class="valeur"><?= $g('code_cf') ?></div>
                <div class="label">TP assignataire</div>
                <div class="valeur"><?= $g('tp_assignataire') ?></div>
            </td>
            <td style="width:30%">
                <div class="label">N&deg; BE</div>
                <br>
                <div class="label">N&deg; OP</div>
                <div class="valeur jaune"><?= htmlspecialchars($d['numero_op']) ?></div>
                <br>
                <div class="label">Financement</div>
                <div class="valeur"><?= $g('financement') ?></div>
            </td>
        </tr>
    </table>

    <table style="margin-top:6px">
        <tr>
            <td colspan="3"><?= $texte_credits ?></td>
        </tr>
        <tr>
            <td>
                <div class="label">Programme</div>
                <div class="valeur"><?= $g('programme') ?></div>
            </td>
            <td>
                <div class="label">Activite / Projet</div>
                <div class="valeur"><?= $g('activite_projet') ?></div>
            </td>
            <td>
                <div class="label"><?= $sect_conv ?></div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="label">Imputation administrative - CDD</div>
                <div class="valeur"><?= $g('imputation_administrative') ?></div>
            </td>
            <td>
                <div class="label">Mission / Exercice</div>
                <div class="valeur"><?= $g('mission') ?> / <?= $g('exercice') ?></div>
            </td>
        </tr>
        <tr>
            <td colspan="3">
                <div class="label">Compte</div>
                <div class="valeur"><?= $g('compte') ?></div>
            </td>
        </tr>
    </table>

    <table style="margin-top:6px">
        <tr>
            <td style="width:50%">
                <div class="label">Montant liquide HT</div>
                <div class="valeur jaune"><?= number_format($d['montant'], 2, ',', ' ') ?> Ar</div>
            </td>
            <td style="width:50%">
                <div class="label">Taxes (TTL, DTI)</div>
                <div class="valeur"><?= number_format((float) $g('taxes'), 2, ',', ' ') ?> Ar</div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="label">Montant liquide</div>
                <div class="valeur"><?= number_format($d['montant'] + (float) $g('taxes'), 2, ',', ' ') ?> Ar</div>
            </td>
        </tr>
    </table>

    <table style="margin-top:6px">
        <tr>
            <td>
                <div class="label">Periode liquidee — Date debut</div>
                <div class="valeur"><?= htmlspecialchars($d['periode_debut']) ?></div>
            </td>
            <td>
                <div class="label">Periode liquidee — Date fin</div>
                <div class="valeur"><?= htmlspecialchars($d['periode_fin']) ?></div>
            </td>
        </tr>
    </table>

    <table style="margin-top:6px">
        <tr>
            <td>
                <div class="label">Numero d'identification du TITULAIRE</div>
                <div class="valeur jaune"><?= htmlspecialchars($d['matricule']) ?></div>
            </td>
            <td>
                <div class="label">Titulaire</div>
                <div class="valeur"><?= htmlspecialchars($d['nom_agent']) ?></div>
            </td>
        </tr>
    </table>

    <table style="margin-top:6px">
        <tr>
            <td colspan="2">
                <div class="label">Objet de la depense</div>
                <div class="valeur">
                    Paiement d'indemnite(s) de mission interieure de garde permanente a/n de
                    <?= htmlspecialchars($d['nom_agent']) ?>
                    au titre du mois de
                    <span class="jaune"><?= htmlspecialchars($d['mois_debut']) ?></span>
                    au
                    <span class="jaune"><?= htmlspecialchars($d['mois_fin']) ?></span>
                    <span class="jaune"><?= htmlspecialchars($d['annee']) ?></span>,
                    aupres de la <?= $g('poste_comptable_nom') ?>.
                </div>
            </td>
        </tr>
    </table>

    <table style="margin-top:6px">
        <tr>
            <td style="width:50%">
                <div class="label">Montant mandate (A)</div>
                <div class="valeur"><?= number_format($d['montant'] + (float) $g('taxes'), 2, ',', ' ') ?> Ar</div>
            </td>
            <td style="width:50%">
                <div class="label">A precompter</div>
                <div class="valeur"><?= number_format((float) $g('a_precompter'), 2, ',', ' ') ?> Ar</div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="label">Net a payer</div>
                <div class="valeur" style="font-size:13px"><?= number_format($d['montant'] + (float) $g('taxes') + (float) $g('a_precompter'), 2, ',', ' ') ?> Ar</div>
            </td>
        </tr>
    </table>

    <table style="margin-top:6px">
        <tr>
            <td colspan="2">
                <div class="label">Arrete par nous le present ordre de paiement a la somme de (A) :</div>
                <div class="valeur"><?= htmlspecialchars($d['montant_lettres']) ?></div>
            </td>
        </tr>
    </table>

    <table style="margin-top:20px">
        <tr>
            <td style="width:50%; height:80px;">
                Fait a <?= $g('lieu') ?>, le ..........................................
                <br><br><br>
                (Signature et timbre du Chef de Poste)
            </td>
            <td style="width:50%; height:80px;">
                Pour acquit de la somme indiquee ci-contre (A)
                <br><br><br>
                A .........................................................................
                <br><br>
                Le ........................................................................
            </td>
        </tr>
    </table>

    </body>
    </html>
    <?php
    return ob_get_clean();
}
