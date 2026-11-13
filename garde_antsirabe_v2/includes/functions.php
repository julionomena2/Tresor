<?php
/**
 * includes/functions.php  (VERSION 2)
 * -------------------------------------------------------------
 * Fonctions "métier" de l'application : paramètres, agents,
 * pointages, calcul d'indemnité, numéro d'OP, JOURNAL D'AUDIT,
 * conversion nombre -> lettres.
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/../config/database.php';

/* =====================================================================
   PARAMETRES (constantes du poste comptable, table "parametres")
   ===================================================================== */

/** Récupère une valeur de la table parametres par sa clé. */
function get_parametre(string $cle, string $defaut = ''): string
{
    $stmt = get_pdo()->prepare('SELECT valeur FROM parametres WHERE cle = :cle');
    $stmt->execute(['cle' => $cle]);
    $valeur = $stmt->fetchColumn();
    return $valeur !== false ? $valeur : $defaut;
}

/** Récupère TOUS les paramètres d'un coup : ['cle' => 'valeur', ...] */
function get_tous_parametres(): array
{
    return get_pdo()->query('SELECT cle, valeur FROM parametres ORDER BY cle')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/* =====================================================================
   AGENTS
   ===================================================================== */

/** Liste de tous les agents (actifs d'abord), triée par nom. */
function get_tous_agents(): array
{
    return get_pdo()->query('SELECT * FROM agents ORDER BY statut ASC, nom_prenoms ASC')->fetchAll();
}

/** Un agent à partir de son matricule, ou null. */
function get_agent(string $matricule): ?array
{
    $stmt = get_pdo()->prepare('SELECT * FROM agents WHERE matricule = :m');
    $stmt->execute(['m' => $matricule]);
    return $stmt->fetch() ?: null;
}

/** Recherche un agent par son identifiant (matricule). Retourne l'agent ou null. */
function rechercher_agent_par_identifiant(string $identifiant): ?array
{
    $identifiant = trim($identifiant);
    if ($identifiant === '') {
        return null;
    }
    return get_agent($identifiant);
}

/** Alias pratique : chercher un agent par son matricule. */
function rechercher_agent(string $identifiant): ?array
{
    return rechercher_agent_par_identifiant($identifiant);
}

/** Liste des agents dont le matricule (identifiant) contient le terme recherché. */
function rechercher_agents_par_identifiant(string $terme = ''): array
{
    $terme = trim($terme);
    $agents = get_tous_agents();
    if ($terme === '') {
        return $agents;
    }
    return array_values(array_filter($agents, static function (array $a) use ($terme): bool {
        return stripos((string) ($a['matricule'] ?? ''), $terme) !== false;
    }));
}

/* =====================================================================
   UTILISATEURS (comptes de connexion)
   ===================================================================== */

/** Nombre d'inscriptions en attente de validation (badge dans le menu admin). */
function compter_utilisateurs_en_attente(): int
{
    return (int) get_pdo()->query("SELECT COUNT(*) FROM utilisateurs WHERE statut = 'en_attente'")->fetchColumn();
}

/* =====================================================================
   HISTORIQUE / JOURNAL D'AUDIT
   ===================================================================== */

/**
 * Enregistre une action dans l'historique (table "historique").
 *
 * @param string      $action       code court de l'action (ex: 'ajout_pointage')
 * @param string      $table_cible  table concernée (ex: 'pointages')
 * @param string|null $cible_id     id / matricule de la ligne concernée
 * @param string      $description  phrase lisible décrivant l'action
 * @param array|null  $avant        valeurs AVANT modification (sera stocké en JSON)
 * @param array|null  $apres        valeurs APRES modification (sera stocké en JSON)
 * @param array|null  $acteur       ['id','username','role'] ; si null on prend l'utilisateur connecté
 *
 * IMPORTANT : ne jamais passer de mot de passe (ni hash) dans $avant/$apres.
 */
function journaliser(
    string $action,
    ?string $table_cible,
    ?string $cible_id,
    string $description,
    ?array $avant = null,
    ?array $apres = null,
    ?array $acteur = null
): void {
    // Si aucun acteur n'est fourni, on utilise l'utilisateur actuellement connecté
    if ($acteur === null && function_exists('utilisateur_courant')) {
        $acteur = utilisateur_courant();
    }
    $acteur = $acteur ?? ['id' => null, 'username' => 'systeme', 'role' => 'systeme'];

    $json = fn(?array $d) => $d === null ? null : json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = get_pdo()->prepare(
        'INSERT INTO historique
            (utilisateur_id, username, role, action, table_cible, cible_id, description, donnees_avant, donnees_apres, ip_adresse)
         VALUES
            (:uid, :username, :role, :action, :table_cible, :cible_id, :description, :avant, :apres, :ip)'
    );
    $stmt->execute([
        'uid'         => $acteur['id'],
        'username'    => mb_substr((string) $acteur['username'], 0, 50),
        'role'        => mb_substr((string) $acteur['role'], 0, 20),
        'action'      => $action,
        'table_cible' => $table_cible,
        'cible_id'    => $cible_id,
        'description' => mb_substr($description, 0, 500),
        'avant'       => $json($avant),
        'apres'       => $json($apres),
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

/**
 * Compare deux versions d'une ligne et ne garde QUE les champs modifiés.
 * Retourne ['avant' => [...], 'apres' => [...]] (vides si rien n'a changé).
 * Permet de ne journaliser que ce qui a réellement changé.
 */
function champs_modifies(array $avant, array $apres): array
{
    $diff_avant = [];
    $diff_apres = [];
    foreach ($apres as $cle => $valeur) {
        $ancienne = $avant[$cle] ?? null;
        if ((string) $ancienne !== (string) $valeur) {
            $diff_avant[$cle] = $ancienne;
            $diff_apres[$cle] = $valeur;
        }
    }
    return ['avant' => $diff_avant, 'apres' => $diff_apres];
}

/* =====================================================================
   POINTAGES
   ===================================================================== */

/** Un pointage par son id, ou null. */
function get_pointage(int $id): ?array
{
    $stmt = get_pdo()->prepare('SELECT * FROM pointages WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
}

/**
 * Dernier pointage enregistré, TOUS AGENTS CONFONDUS : sert à pré-remplir
 * l'heure d'arrivée du prochain agent (relève).
 */
function get_dernier_pointage_global(): ?array
{
    $stmt = get_pdo()->query('SELECT * FROM pointages ORDER BY date_fin DESC, heure_fin DESC LIMIT 1');
    return $stmt->fetch() ?: null;
}

/**
 * Enregistre une garde de 24h. Fin / durée / montant sont calculés ici.
 * Retourne l'id du pointage créé.
 */
function creer_pointage(string $matricule, string $date_debut, string $heure_debut, ?string $reference_om, ?int $saisi_par): int
{
    $pdo = get_pdo();

    $debut = new DateTime($date_debut . ' ' . $heure_debut);
    $fin   = (clone $debut)->modify('+24 hours');
    $montant = (float) get_parametre('indemnite_par_garde', '30000');

    $stmt = $pdo->prepare(
        'INSERT INTO pointages (matricule, date_debut, heure_debut, date_fin, heure_fin, duree_heures, montant, reference_om, saisi_par)
         VALUES (:matricule, :date_debut, :heure_debut, :date_fin, :heure_fin, 24, :montant, :reference_om, :saisi_par)'
    );
    $stmt->execute([
        'matricule'    => $matricule,
        'date_debut'   => $debut->format('Y-m-d'),
        'heure_debut'  => $debut->format('H:i:s'),
        'date_fin'     => $fin->format('Y-m-d'),
        'heure_fin'    => $fin->format('H:i:s'),
        'montant'      => $montant,
        'reference_om' => $reference_om,
        'saisi_par'    => $saisi_par,
    ]);

    return (int) $pdo->lastInsertId();
}

/** Modifie une garde existante (la fin est recalculée : début + 24h). */
function modifier_pointage(int $id, string $matricule, string $date_debut, string $heure_debut, ?string $reference_om): void
{
    $debut = new DateTime($date_debut . ' ' . $heure_debut);
    $fin   = (clone $debut)->modify('+24 hours');

    $stmt = get_pdo()->prepare(
        'UPDATE pointages
            SET matricule = :matricule, date_debut = :date_debut, heure_debut = :heure_debut,
                date_fin = :date_fin, heure_fin = :heure_fin, reference_om = :reference_om
          WHERE id = :id'
    );
    $stmt->execute([
        'matricule'    => $matricule,
        'date_debut'   => $debut->format('Y-m-d'),
        'heure_debut'  => $debut->format('H:i:s'),
        'date_fin'     => $fin->format('Y-m-d'),
        'heure_fin'    => $fin->format('H:i:s'),
        'reference_om' => $reference_om,
        'id'           => $id,
    ]);
}

/**
 * Liste des pointages avec nom de l'agent et auteur de la saisie.
 * Filtres possibles : ['matricule' => ..., 'du' => 'Y-m-d', 'au' => 'Y-m-d']
 */
function get_pointages(array $filtres = [], int $limite = 300): array
{
    $sql = 'SELECT p.*, a.nom_prenoms, u.username AS saisi_par_username
              FROM pointages p
              JOIN agents a ON a.matricule = p.matricule
              LEFT JOIN utilisateurs u ON u.id = p.saisi_par
             WHERE 1 = 1';
    $params = [];

    if (!empty($filtres['matricule'])) { $sql .= ' AND p.matricule = :matricule'; $params['matricule'] = $filtres['matricule']; }
    if (!empty($filtres['du']))        { $sql .= ' AND p.date_debut >= :du';      $params['du'] = $filtres['du']; }
    if (!empty($filtres['au']))        { $sql .= ' AND p.date_debut <= :au';      $params['au'] = $filtres['au']; }

    $sql .= ' ORDER BY p.date_debut DESC, p.heure_debut DESC LIMIT ' . (int) $limite;

    $stmt = get_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Raccourci : les derniers pointages. */
function get_pointages_recents(int $limite = 30): array
{
    return get_pointages([], $limite);
}

/**
 * Cumul d'indemnité d'UN agent sur une période (bornes incluses),
 * selon la date de début de chaque garde.
 * Retourne ['nb_gardes' => int, 'montant_total' => float, 'pointages' => array]
 */
function calculer_indemnite_periode(string $matricule, string $periode_debut, string $periode_fin): array
{
    $stmt = get_pdo()->prepare(
        'SELECT * FROM pointages
          WHERE matricule = :matricule AND date_debut BETWEEN :debut AND :fin
          ORDER BY date_debut ASC'
    );
    $stmt->execute(['matricule' => $matricule, 'debut' => $periode_debut, 'fin' => $periode_fin]);
    $pointages = $stmt->fetchAll();

    $montant_total = 0.0;
    foreach ($pointages as $p) {
        $montant_total += (float) $p['montant'];
    }

    return ['nb_gardes' => count($pointages), 'montant_total' => $montant_total, 'pointages' => $pointages];
}

/* =====================================================================
   NUMERO D'ORDRE DE PAIEMENT (cellule jaune I5, ex: "01/35 /2026")
   ===================================================================== */

function generer_numero_op(string $annee): string
{
    $pdo = get_pdo();
    $code_poste = get_parametre('poste_comptable_code', '35');

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ordres_paiement WHERE numero_op LIKE :pattern');
    $stmt->execute(['pattern' => '%/' . $annee]);
    $sequence = (int) $stmt->fetchColumn() + 1;

    $numero = sprintf('%02d/%s /%s', $sequence, $code_poste, $annee);

    // Sécurité : si ce numéro existe déjà, on incrémente jusqu'à en trouver un libre
    $verif = $pdo->prepare('SELECT COUNT(*) FROM ordres_paiement WHERE numero_op = :n');
    while (true) {
        $verif->execute(['n' => $numero]);
        if ((int) $verif->fetchColumn() === 0) break;
        $sequence++;
        $numero = sprintf('%02d/%s /%s', $sequence, $code_poste, $annee);
    }
    return $numero;
}

/** Enregistre un OP généré (historique des PDF). */
function enregistrer_ordre_paiement(string $numero_op, string $matricule, string $periode_debut, string $periode_fin, int $nb_gardes, float $montant, ?int $genere_par = null): void
{
    $stmt = get_pdo()->prepare(
        'INSERT INTO ordres_paiement (numero_op, matricule, periode_debut, periode_fin, nb_gardes, montant, genere_par)
         VALUES (:numero_op, :matricule, :periode_debut, :periode_fin, :nb_gardes, :montant, :genere_par)'
    );
    $stmt->execute([
        'numero_op' => $numero_op, 'matricule' => $matricule,
        'periode_debut' => $periode_debut, 'periode_fin' => $periode_fin,
        'nb_gardes' => $nb_gardes, 'montant' => $montant, 'genere_par' => $genere_par,
    ]);
}

/* =====================================================================
   OUTILS DIVERS
   ===================================================================== */

/** Vérifie qu'une chaîne est une date valide au format Y-m-d. */
function date_valide(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

/** Vérifie qu'une chaîne est une heure valide au format HH:MM. */
function heure_valide(string $h): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h);
}

/** Nom du mois en français (MAJUSCULES) à partir d'une date Y-m-d. */
function mois_nom_fr(string $date): string
{
    $mois = [1 => 'JANVIER', 2 => 'FEVRIER', 3 => 'MARS', 4 => 'AVRIL', 5 => 'MAI', 6 => 'JUIN',
             7 => 'JUILLET', 8 => 'AOUT', 9 => 'SEPTEMBRE', 10 => 'OCTOBRE', 11 => 'NOVEMBRE', 12 => 'DECEMBRE'];
    return $mois[(int) date('n', strtotime($date))];
}

/**
 * Convertit un montant (Ariary) en toutes lettres - équivalent de "=lettre(...)" d'Excel.
 * Ex: 210000 -> "DEUX CENT DIX MILLE ARIARY"
 *
 * CORRIGÉ en v2 : "trois cent mille" (cent/vingt invariables devant "mille"),
 * "soixante et onze", et 77/97 ("soixante-dix-sept", "quatre-vingt-dix-sept").
 */
function nombre_en_lettres(float $nombre): string
{
    $nombre = (int) round($nombre);
    if ($nombre === 0) {
        return 'ZERO ARIARY';
    }

    $unites      = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf'];
    $dix_a_seize = ['dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize'];
    $dizaines    = ['', '', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante', 'quatre-vingt', 'quatre-vingt'];

    // Convertit 0..999. $accord = true : "cents"/"quatre-vingts" prennent un "s" quand ils terminent le groupe.
    $convertir_groupe = function (int $n, bool $accord) use ($unites, $dix_a_seize, $dizaines): string {
        if ($n === 0) return '';

        $centaines = intdiv($n, 100);
        $reste = $n % 100;
        $texte = '';

        if ($centaines > 0) {
            $texte .= ($centaines > 1 ? $unites[$centaines] . ' ' : '') . 'cent';
            if ($reste === 0 && $centaines > 1 && $accord) $texte .= 's';
            if ($reste > 0) $texte .= ' ';
        }

        if ($reste > 0) {
            if ($reste < 10) {
                $texte .= $unites[$reste];
            } elseif ($reste < 17) {
                $texte .= $dix_a_seize[$reste - 10];
            } elseif ($reste < 20) {
                $texte .= 'dix-' . $unites[$reste - 10];
            } else {
                $d = intdiv($reste, 10);
                $u = $reste % 10;
                if ($d === 7 || $d === 9) {
                    // 70-79 et 90-99 : soixante-dix..., quatre-vingt-dix...
                    if ($d === 7 && $u === 1) {
                        $texte .= 'soixante et onze';
                    } else {
                        $suffixe = (10 + $u) < 17 ? $dix_a_seize[$u] : 'dix-' . $unites[$u];
                        $texte .= $dizaines[$d] . '-' . $suffixe;
                    }
                } else {
                    $texte .= $dizaines[$d];
                    if ($u === 1 && $d !== 8) {
                        $texte .= ' et un';
                    } elseif ($u > 0) {
                        $texte .= '-' . $unites[$u];
                    } elseif ($d === 8 && $accord) {
                        $texte .= 's'; // "quatre-vingts" seulement s'il termine le groupe
                    }
                }
            }
        }
        return trim($texte);
    };

    $groupes = [1000000000 => 'milliard', 1000000 => 'million', 1000 => 'mille'];
    $texte = '';
    $reste = $nombre;

    foreach ($groupes as $valeur => $mot) {
        if ($reste >= $valeur) {
            $qte = intdiv($reste, $valeur);
            $reste = $reste % $valeur;
            if ($valeur === 1000) {
                // "mille" est invariable et n'accorde pas cent/vingt : "trois cent mille"
                $texte .= ($qte === 1 ? '' : $convertir_groupe($qte, false) . ' ') . 'mille ';
            } else {
                $texte .= $convertir_groupe($qte, true) . ' ' . $mot . ($qte > 1 ? 's' : '') . ' ';
            }
        }
    }

    $texte .= $convertir_groupe($reste, true);
    $texte = trim(preg_replace('/\s+/', ' ', $texte));

    return mb_strtoupper($texte) . ' ARIARY';
}
