<?php
/**
 * admin/historique.php  (CÔTÉ ADMINISTRATEUR)
 * Journal d'audit : qui a fait quoi, quand, avec les valeurs avant/après.
 * Lecture seule : aucune suppression possible depuis l'application.
 */
require_once __DIR__ . '/../includes/auth.php';
exiger_connexion(['admin']);

$pdo = get_pdo();
$par_page = 50;

// ---- Filtres -----------------------------------------------------------
$f_user   = trim($_GET['utilisateur'] ?? '');
$f_action = trim($_GET['action'] ?? '');
$f_table  = trim($_GET['table'] ?? '');
$f_du     = date_valide($_GET['du'] ?? '') ? $_GET['du'] : '';
$f_au     = date_valide($_GET['au'] ?? '') ? $_GET['au'] : '';
$page     = max(1, (int) ($_GET['page'] ?? 1));

$where = ' WHERE 1 = 1';
$params = [];
if ($f_user !== '')   { $where .= ' AND username LIKE :u';       $params['u'] = '%' . $f_user . '%'; }
if ($f_action !== '') { $where .= ' AND action = :a';            $params['a'] = $f_action; }
if ($f_table !== '')  { $where .= ' AND table_cible = :t';       $params['t'] = $f_table; }
if ($f_du !== '')     { $where .= ' AND date_action >= :du';     $params['du'] = $f_du . ' 00:00:00'; }
if ($f_au !== '')     { $where .= ' AND date_action <= :au';     $params['au'] = $f_au . ' 23:59:59'; }

$stmt = $pdo->prepare('SELECT COUNT(*) FROM historique' . $where);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$nb_pages = max(1, (int) ceil($total / $par_page));
$page = min($page, $nb_pages);

$stmt = $pdo->prepare('SELECT * FROM historique' . $where . ' ORDER BY id DESC LIMIT ' . $par_page . ' OFFSET ' . (($page - 1) * $par_page));
$stmt->execute($params);
$lignes = $stmt->fetchAll();

// Listes pour les filtres déroulants
$actions = $pdo->query('SELECT DISTINCT action FROM historique ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$tables  = $pdo->query('SELECT DISTINCT table_cible FROM historique WHERE table_cible IS NOT NULL ORDER BY table_cible')->fetchAll(PDO::FETCH_COLUMN);

/** Affiche un JSON stocké sous forme lisible. */
function json_joli(?string $json): string
{
    if ($json === null || $json === '') return '';
    $d = json_decode($json, true);
    return json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $json;
}

/** Lien de pagination en conservant les filtres. */
function lien_page(int $p): string
{
    $q = $_GET;
    $q['page'] = $p;
    return '?' . http_build_query($q);
}

$titre_page = 'Historique';
require __DIR__ . '/../includes/header.php';
?>

<h1>Historique des actions</h1>
<p>Journal d'audit en lecture seule : connexions, saisies, modifications, suppressions, décisions sur les comptes, OP générés.</p>

<div class="carte">
    <form method="get" class="form-inline">
        <div class="form-ligne"><label for="utilisateur">Utilisateur</label><input type="text" id="utilisateur" name="utilisateur" value="<?= htmlspecialchars($f_user) ?>"></div>
        <div class="form-ligne">
            <label for="action">Action</label>
            <select id="action" name="action">
                <option value="">Toutes</option>
                <?php foreach ($actions as $a): ?><option value="<?= htmlspecialchars($a) ?>" <?= $f_action === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="form-ligne">
            <label for="table">Table</label>
            <select id="table" name="table">
                <option value="">Toutes</option>
                <?php foreach ($tables as $t): ?><option value="<?= htmlspecialchars($t) ?>" <?= $f_table === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="form-ligne"><label for="du">Du</label><input type="date" id="du" name="du" value="<?= htmlspecialchars($f_du) ?>"></div>
        <div class="form-ligne"><label for="au">Au</label><input type="date" id="au" name="au" value="<?= htmlspecialchars($f_au) ?>"></div>
        <div class="form-ligne">
            <button type="submit">Filtrer</button>
            <a class="btn btn-secondaire" href="historique.php">Réinitialiser</a>
        </div>
    </form>
</div>

<div class="carte">
    <p><?= $total ?> entrée(s) — page <?= $page ?> / <?= $nb_pages ?></p>
    <div class="table-scroll">
    <table>
        <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Description</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($lignes as $h): ?>
            <tr>
                <td style="white-space:nowrap"><?= htmlspecialchars($h['date_action']) ?></td>
                <td><?= htmlspecialchars($h['username']) ?><br><small style="color:#64748b"><?= htmlspecialchars($h['role']) ?></small></td>
                <td><span class="badge badge-action"><?= htmlspecialchars($h['action']) ?></span></td>
                <td>
                    <?= htmlspecialchars($h['description']) ?>
                    <?php if ($h['donnees_avant'] !== null || $h['donnees_apres'] !== null): ?>
                        <details>
                            <summary>Voir les valeurs</summary>
                            <?php if ($h['donnees_avant'] !== null): ?>
                                <div class="col-avant"><strong>Avant :</strong><pre class="json"><?= htmlspecialchars(json_joli($h['donnees_avant'])) ?></pre></div>
                            <?php endif; ?>
                            <?php if ($h['donnees_apres'] !== null): ?>
                                <div class="col-apres"><strong>Après :</strong><pre class="json"><?= htmlspecialchars(json_joli($h['donnees_apres'])) ?></pre></div>
                            <?php endif; ?>
                        </details>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($h['ip_adresse'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($lignes)): ?><tr><td colspan="5">Aucune entrée.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php if ($nb_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?><a href="<?= htmlspecialchars(lien_page($page - 1)) ?>">← Précédent</a><?php endif; ?>
            <span class="courant"><?= $page ?></span>
            <?php if ($page < $nb_pages): ?><a href="<?= htmlspecialchars(lien_page($page + 1)) ?>">Suivant →</a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
