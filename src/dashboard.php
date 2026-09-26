<?php
$dbFile = __DIR__ . '/../cloud_appid.db';

if (!file_exists($dbFile)) {
    die("Datenbank cloud_appid.db nicht gefunden!");
}

$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Suchparameter aus der URL auslesen
$search   = $_GET['q'] ?? '';
$category = $_GET['category'] ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = 50; // Einträge pro Seite
$offset   = ($page - 1) * $limit;

// Dynamische SQL-Query aufbauen
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "name LIKE :search";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($category)) {
    $where[] = "category = :category";
    $params[':category'] = $category;
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Daten & Gesamtzahl abfragen
$stmt = $db->prepare("SELECT id, name, category, subcategory, technology, risk, last_update_date FROM cloud_appids $whereSql LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countStmt = $db->prepare("SELECT COUNT(*) FROM cloud_appids $whereSql");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Cloud-AppID Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f4f6f9; }
        h1 { color: #333; }
        .filter-box { background: #fff; padding: 15px; border-radius: 5px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        input, button { padding: 8px 12px; margin-right: 10px; }
        table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        th, td { padding: 10px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background: #007bff; color: white; }
        tr:hover { background: #f1f1f1; }
        .badge { padding: 3px 7px; border-radius: 3px; font-weight: bold; color: #fff; }
        .risk-1 { background: green; } .risk-3 { background: orange; } .risk-5 { background: red; }
        .pagination { margin-top: 15px; }
        .pagination a { padding: 5px 10px; border: 1px solid #ccc; background: #fff; text-decoration: none; color: #333; }
        .pagination strong { padding: 5px 10px; background: #007bff; color: white; }
    </style>
</head>
<body>

<h1>PAN-OS Cloud-AppID Mock Dashboard</h1>

<div class="filter-box">
    <form method="GET">
        <input type="text" name="q" placeholder="App-Name suchen..." value="<?= htmlspecialchars($search) ?>">
        <button type="submit">Suchen</button>
        <a href="dashboard.php">Reset</a>
        <span style="float:right; font-weight:bold;">Treffer: <?= number_format($totalRows, 0, ',', '.') ?> Apps</span>
    </form>
</div>

<table>
    <thead>
    <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Kategorie</th>
        <th>Subkategorie</th>
        <th>Technologie</th>
        <th>Risiko</th>
        <th>Letztes Update</th>
        <th>Aktion</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($apps as $app): ?>
        <tr>
            <td><?= $app['id'] ?></td>
            <td><strong><?= htmlspecialchars($app['name']) ?></strong></td>
            <td><?= htmlspecialchars($app['category'] ?? '-') ?></td>
            <td><?= htmlspecialchars($app['subcategory'] ?? '-') ?></td>
            <td><?= htmlspecialchars($app['technology'] ?? '-') ?></td>
            <td>
                    <span class="badge risk-<?= $app['risk'] ?? 3 ?>">
                        Risk <?= $app['risk'] ?? '?' ?>
                    </span>
            </td>
            <td><?= $app['last_update_date'] ?? '-' ?></td>
            <td>
                <a href="index.php?cmd=%3Cshow%3E%3Ccloud-appid%3E%3Capplication%3E<?= urlencode($app['name']) ?>%3C/application%3E%3C/cloud-appid%3E%3C/show%3E" target="_blank">XML API</a>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($apps)): ?>
        <tr><td colspan="8">Keine Apps gefunden.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<!-- Pagination -->
<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="?q=<?= urlencode($search) ?>&page=<?= $page - 1 ?>">&laquo; Zurück</a>
    <?php endif; ?>

    <span>Seite <?= $page ?> von <?= $totalPages ?></span>

    <?php if ($page < $totalPages): ?>
        <a href="?q=<?= urlencode($search) ?>&page=<?= $page + 1 ?>">Weiter &raquo;</a>
    <?php endif; ?>
</div>

</body>
</html>