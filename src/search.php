<?php
// src/search.php
ini_set('memory_limit', '-1');

// Relative Pfade ins übergeordnete Verzeichnis
$dbPath        = __DIR__ . '/../cloud_appid.db';
$containerTxt  = __DIR__ . '/../cloud-container.txt';
$predefinedXml = __DIR__ . '/../predefined.xml';

$q              = trim($_GET['q'] ?? '');
$sourceFilter   = $_GET['source'] ?? 'all'; // Standard: Alle Quellen
$appPage        = max(1, (int)($_GET['app_page'] ?? 1));
$containerPage  = max(1, (int)($_GET['container_page'] ?? 1));
$perPage        = 25;

$appResults       = [];
$containerResults = [];

if ($q !== '') {

    // =========================================================
    // 1. APPLICATION SUCHE (DB)
    // =========================================================
    if (($sourceFilter === 'all' || strpos($sourceFilter, 'db') === 0) && file_exists($dbPath)) {
        try {
            $pdo = new PDO("sqlite:$dbPath");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Dynamischer SQL-Filter für ID-Bereiche
            $sql = "SELECT id, name, category, subcategory, technology, risk, application_container 
                    FROM cloud_appids 
                    WHERE (name LIKE :q OR CAST(id AS TEXT) LIKE :q OR category LIKE :q)";

            if ($sourceFilter === 'db_predefined') {
                $sql .= " AND CAST(id AS INTEGER) < 1000000";
            } elseif ($sourceFilter === 'db_cloud_app') {
                $sql .= " AND CAST(id AS INTEGER) >= 1000000";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([':q' => "%$q%"]);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $isPredefined = ((int)$row['id'] < 1000000);

                $row['source_type']  = $isPredefined ? 'predefined' : 'cloud-app';
                $row['source_label'] = 'cloud_appid.db';
                $appResults[] = $row;
            }
        } catch (Exception $e) {
            // Stille Behandlung oder Logging
        }
    }

    // =========================================================
    // 2. APPLICATION-CONTAINER SUCHE (cloud-container.txt + predefined.xml)
    // =========================================================

    // A) cloud-container.txt
    if (($sourceFilter === 'all' || $sourceFilter === 'cloud_container') && file_exists($containerTxt)) {
        $handle = fopen($containerTxt, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line) || strpos($line, 'Id') === 0 || strpos($line, '---') === 0) continue;
                $cols = preg_split('/\s{2,}/', $line);
                if (count($cols) >= 2) {
                    $id   = trim($cols[0]);
                    $name = trim($cols[1]);
                    if (stripos($name, $q) !== false || stripos($id, $q) !== false) {
                        $containerResults[] = [
                            'id'             => $id,
                            'name'           => $name,
                            'receiving_time' => $cols[2] ?? 'N/A',
                            'task_id'        => $cols[3] ?? 'N/A',
                            'source_type'    => 'cloud-app',
                            'source_label'   => 'cloud-container.txt'
                        ];
                    }
                }
            }
            fclose($handle);
        }
    }

    // B) predefined.xml (/predefined/application-container)
    if (($sourceFilter === 'all' || $sourceFilter === 'predefined_xml') && file_exists($predefinedXml)) {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($predefinedXml);
        if ($xml !== false) {
            $nodes = $xml->xpath('/predefined/application-container/entry') ?: $xml->xpath('//application-container/entry');
            if ($nodes) {
                foreach ($nodes as $node) {
                    $id   = (string)($node['id'] ?? 'N/A');
                    $name = (string)($node['name'] ?? '');
                    if (stripos($name, $q) !== false || stripos($id, $q) !== false) {
                        $containerResults[] = [
                            'id'             => $id,
                            'name'           => $name,
                            'receiving_time' => '-',
                            'task_id'        => '-',
                            'source_type'    => 'predefined',
                            'source_label'   => 'predefined.xml'
                        ];
                    }
                }
            }
        }
    }
}

// Gesamtanzahl für Pagination berechnen
$totalApps       = count($appResults);
$totalContainers = count($containerResults);

$totalAppPages       = max(1, (int)ceil($totalApps / $perPage));
$totalContainerPages = max(1, (int)ceil($totalContainers / $perPage));

// Ausschnitte für die aktuelle Seite festlegen (max. 25 Einträge)
$slicedApps       = array_slice($appResults, ($appPage - 1) * $perPage, $perPage);
$slicedContainers = array_slice($containerResults, ($containerPage - 1) * $perPage, $perPage);

// Helper-Funktion für die Seitennavigation
function renderPagination(int $currentPage, int $totalPages, string $paramName, array $extraParams) {
    if ($totalPages <= 1) return;

    echo '<nav class="my-2"><ul class="pagination pagination-sm justify-content-center m-0">';

    $prevPage = max(1, $currentPage - 1);
    $prevUrl  = '?' . http_build_query(array_merge($extraParams, [$paramName => $prevPage]));
    $disabledPrev = ($currentPage <= 1) ? 'disabled' : '';
    echo "<li class=\"page-item {$disabledPrev}\"><a class=\"page-link\" href=\"{$prevUrl}\">&laquo;</a></li>";

    $start = max(1, $currentPage - 2);
    $end   = min($totalPages, $currentPage + 2);

    for ($i = $start; $i <= $end; $i++) {
        $active = ($i === $currentPage) ? 'active' : '';
        $url    = '?' . http_build_query(array_merge($extraParams, [$paramName => $i]));
        echo "<li class=\"page-item {$active}\"><a class=\"page-link\" href=\"{$url}\">{$i}</a></li>";
    }

    $nextPage = min($totalPages, $currentPage + 1);
    $nextUrl  = '?' . http_build_query(array_merge($extraParams, [$paramName => $nextPage]));
    $disabledNext = ($currentPage >= $totalPages) ? 'disabled' : '';
    echo "<li class=\"page-item {$disabledNext}\"><a class=\"page-link\" href=\"{$nextUrl}\">&raquo;</a></li>";

    echo '</ul></nav>';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Gesamtsuche - Applications & Container</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; }
        .source-badge-cloud-app { background-color: #0d6efd; }
        .source-badge-predefined { background-color: #6f42c1; }
    </style>
</head>
<body class="py-4">
<div class="container-fluid px-4">
    <h1 class="mb-4">Globale Namenssuche</h1>

    <!-- Suchmaske -->
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-7">
                    <label for="q" class="form-label fw-bold">Suchbegriff (Name / ID)</label>
                    <input type="text" id="q" name="q" class="form-control form-control-lg" placeholder="Suchbegriff eingeben..." value="<?= htmlspecialchars($q) ?>" required>
                </div>
                <div class="col-md-3">
                    <label for="source" class="form-label fw-bold">Quelle</label>
                    <select id="source" name="source" class="form-select form-select-lg">
                        <option value="all" <?= $sourceFilter === 'all' ? 'selected' : '' ?>>Alle Quellen</option>
                        <option value="db_all" <?= $sourceFilter === 'db_all' ? 'selected' : '' ?>>DB: Alle Einträge</option>
                        <option value="db_predefined" <?= $sourceFilter === 'db_predefined' ? 'selected' : '' ?>>DB: Nur Predefined (ID < 1 Mio)</option>
                        <option value="db_cloud_app" <?= $sourceFilter === 'db_cloud_app' ? 'selected' : '' ?>>DB: Nur Cloud-App (ID ≥ 1 Mio)</option>
                        <option value="cloud_container" <?= $sourceFilter === 'cloud_container' ? 'selected' : '' ?>>cloud-container.txt (Container)</option>
                        <option value="predefined_xml" <?= $sourceFilter === 'predefined_xml' ? 'selected' : '' ?>>predefined.xml (Container)</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary btn-lg w-100">Suchen</button>
                    <?php if ($q !== ''): ?>
                        <a href="search.php" class="btn btn-outline-secondary btn-lg">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if ($q !== ''): ?>

        <!-- ========================================================= -->
        <!-- TABELLE 1: APPLICATIONS -->
        <!-- ========================================================= -->
        <?php if ($sourceFilter === 'all' || strpos($sourceFilter, 'db') === 0): ?>
            <div class="card mb-5 shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-3">
                    <h4 class="m-0">1. Applications (<?= $totalApps ?> Treffer)</h4>
                    <span class="badge bg-light text-dark">Max. 25 pro Seite</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($slicedApps)): ?>
                        <div class="p-4 text-center text-muted">Keine Applications für "<?= htmlspecialchars($q) ?>" gefunden.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0" style="font-size: 0.9rem;">
                                <thead class="table-secondary">
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Typ / Quelle</th>
                                    <th>Kategorie</th>
                                    <th>Subkategorie</th>
                                    <th>Technologie</th>
                                    <th>Container</th>
                                    <th>Risiko</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($slicedApps as $row): ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($row['id']) ?></td>
                                        <td><?= htmlspecialchars($row['name']) ?></td>
                                        <td>
                                            <span class="badge source-badge-<?= $row['source_type'] ?>">
                                                <?= htmlspecialchars($row['source_type']) ?>
                                            </span>
                                            <small class="text-muted d-block"><?= htmlspecialchars($row['source_label']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($row['category'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['subcategory'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['technology'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['application_container'] ?? '-') ?></td>
                                        <td>
                                            <?php if (isset($row['risk']) && $row['risk'] > 0): ?>
                                                <span class="badge bg-<?= $row['risk'] >= 4 ? 'danger' : ($row['risk'] == 3 ? 'warning text-dark' : 'info') ?>">
                                                    R<?= $row['risk'] ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($totalAppPages > 1): ?>
                    <div class="card-footer bg-white">
                        <?php renderPagination($appPage, $totalAppPages, 'app_page', ['q' => $q, 'source' => $sourceFilter, 'container_page' => $containerPage]); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ========================================================= -->
        <!-- TABELLE 2: APPLICATION CONTAINER -->
        <!-- ========================================================= -->
        <?php if ($sourceFilter === 'all' || $sourceFilter === 'cloud_container' || $sourceFilter === 'predefined_xml'): ?>
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-3">
                    <h4 class="m-0">2. Application Container (<?= $totalContainers ?> Treffer)</h4>
                    <span class="badge bg-light text-dark">Max. 25 pro Seite</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($slicedContainers)): ?>
                        <div class="p-4 text-center text-muted">Keine Application Container für "<?= htmlspecialchars($q) ?>" gefunden.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0" style="font-size: 0.9rem;">
                                <thead class="table-secondary">
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Typ / Quelle</th>
                                    <th>Receiving Time</th>
                                    <th>Task ID</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($slicedContainers as $row): ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($row['id']) ?></td>
                                        <td><?= htmlspecialchars($row['name']) ?></td>
                                        <td>
                                            <span class="badge source-badge-<?= $row['source_type'] ?>">
                                                <?= htmlspecialchars($row['source_type']) ?>
                                            </span>
                                            <small class="text-muted d-block"><?= htmlspecialchars($row['source_label']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($row['receiving_time']) ?></td>
                                        <td><?= htmlspecialchars($row['task_id']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($totalContainerPages > 1): ?>
                    <div class="card-footer bg-white">
                        <?php renderPagination($containerPage, $totalContainerPages, 'container_page', ['q' => $q, 'source' => $sourceFilter, 'app_page' => $appPage]); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>
</body>
</html>