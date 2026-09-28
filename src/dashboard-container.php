<?php
// container_dashboard.php
ini_set('memory_limit', '-1');

$dbPath        = __DIR__ . '/../cloud_appid.db';
$containerTxt  = __DIR__ . '/../cloud-container.txt';
$predefinedXml = __DIR__ . '/../predefined.xml';

if (!file_exists($dbPath)) {
    die("Fehler: Datenbank $dbPath nicht gefunden!");
}

$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// --- CONFIG & PARAMETER ---
$perPage   = 25;
$activeTab = $_GET['tab'] ?? 'cloud';
$search    = trim($_GET['q'] ?? '');

$pageCloud      = max(1, (int)($_GET['page_cloud'] ?? 1));
$pagePredefined = max(1, (int)($_GET['page_predefined'] ?? 1));

// 1. Cloud-Container einlesen
$rawCloudContainers = [];
if (file_exists($containerTxt)) {
    $handle = fopen($containerTxt, 'r');
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line) || strpos($line, 'Id') === 0 || strpos($line, '---') === 0) continue;
            $cols = preg_split('/\s{2,}/', $line);
            if (count($cols) >= 2) {
                $rawCloudContainers[] = [
                    'id'   => trim($cols[0]),
                    'name' => trim($cols[1]),
                    'time' => $cols[2] ?? '',
                    'task' => $cols[3] ?? ''
                ];
            }
        }
        fclose($handle);
    }
}

// 2. Predefined-Container einlesen
$rawPredefinedContainers = [];
if (file_exists($predefinedXml)) {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($predefinedXml);
    if ($xml !== false) {
        $nodes = $xml->xpath('/predefined/application-container/entry') ?: $xml->xpath('//application-container/entry');
        foreach ($nodes as $node) {
            $rawPredefinedContainers[] = [
                'id'   => (string)($node['id'] ?? 'N/A'),
                'name' => (string)($node['name'] ?? '')
            ];
        }
    }
}

// --- FILTERING (SUCHE) ---
$filterFunc = function($item) use ($search) {
    if ($search === '') return true;
    return (stripos($item['name'], $search) !== false) || (stripos($item['id'], $search) !== false);
};

$allCloudContainers      = array_values(array_filter($rawCloudContainers, $filterFunc));
$allPredefinedContainers = array_values(array_filter($rawPredefinedContainers, $filterFunc));

// --- PAGING CALCULATION ---
$totalCloud      = count($allCloudContainers);
$totalPagesCloud = max(1, (int)ceil($totalCloud / $perPage));
$offsetCloud     = ($pageCloud - 1) * $perPage;
$cloudContainers = array_slice($allCloudContainers, $offsetCloud, $perPage);

$totalPredefined      = count($allPredefinedContainers);
$totalPagesPredefined = max(1, (int)ceil($totalPredefined / $perPage));
$offsetPredefined     = ($pagePredefined - 1) * $perPage;
$predefinedContainers = array_slice($allPredefinedContainers, $offsetPredefined, $perPage);

// Helper: Holen der verknüpften Applikationen aus SQLite
function getAppsForContainer(PDO $pdo, string $containerName): array {
    $stmt = $pdo->prepare("SELECT id, name, category, subcategory, technology, risk, description 
                           FROM cloud_appids 
                           WHERE application_container = :container 
                           ORDER BY CAST(id AS INTEGER) ASC");
    $stmt->execute([':container' => $containerName]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Render Table mit fest zugewiesenen Spaltenprozenten
function renderAppTable(array $apps) {
    if (empty($apps)) {
        echo '<div class="alert alert-light text-muted border-top-0 rounded-0 m-0">Keine verknüpften Applikationen gefunden.</div>';
        return;
    }
    echo '<div class="table-responsive">
            <table class="table table-sm table-hover table-fixed mb-0 bg-white" style="font-size:0.875rem;">
                <thead class="table-secondary">
                    <tr>
                        <th style="width: 10%;">ID</th>
                        <th style="width: 25%;">Name</th>
                        <th style="width: 20%;">Kategorie</th>
                        <th style="width: 20%;">Subkategorie</th>
                        <th style="width: 15%;">Technologie</th>
                        <th style="width: 10%;">Risiko</th>
                    </tr>
                </thead>
                <tbody>';
    foreach ($apps as $app) {
        $badgeClass = match((int)$app['risk']) {
            1 => 'bg-success',
            2 => 'bg-info',
            3 => 'bg-warning text-dark',
            4, 5 => 'bg-danger',
            default => 'bg-secondary'
        };
        echo "<tr>
                <td class=\"fw-bold text-truncate\">" . htmlspecialchars($app['id']) . "</td>
                <td class=\"text-truncate\" title=\"" . htmlspecialchars($app['name']) . "\">" . htmlspecialchars($app['name']) . "</td>
                <td class=\"text-truncate\" title=\"" . htmlspecialchars($app['category'] ?? '-') . "\">" . htmlspecialchars($app['category'] ?? '-') . "</td>
                <td class=\"text-truncate\" title=\"" . htmlspecialchars($app['subcategory'] ?? '-') . "\">" . htmlspecialchars($app['subcategory'] ?? '-') . "</td>
                <td class=\"text-truncate\" title=\"" . htmlspecialchars($app['technology'] ?? '-') . "\">" . htmlspecialchars($app['technology'] ?? '-') . "</td>
                <td><span class=\"badge {$badgeClass}\">R" . htmlspecialchars($app['risk'] ?? '0') . "</span></td>
              </tr>";
    }
    echo '</tbody></table></div>';
}

// Render Pagination Controls
function renderPagination(int $currentPage, int $totalPages, string $tabParam, string $pageParamName, array $extraParams) {
    if ($totalPages <= 1) return;

    echo '<nav class="my-3"><ul class="pagination pagination-sm justify-content-center m-0">';

    // Previous
    $prevPage = max(1, $currentPage - 1);
    $prevUrl = '?' . http_build_query(array_merge($extraParams, ['tab' => $tabParam, $pageParamName => $prevPage]));
    $disabledPrev = ($currentPage <= 1) ? 'disabled' : '';
    echo "<li class=\"page-item {$disabledPrev}\"><a class=\"page-link\" href=\"{$prevUrl}\">&laquo; Zurück</a></li>";

    // Numbers
    $start = max(1, $currentPage - 3);
    $end   = min($totalPages, $currentPage + 3);

    for ($i = $start; $i <= $end; $i++) {
        $active = ($i === $currentPage) ? 'active' : '';
        $url = '?' . http_build_query(array_merge($extraParams, ['tab' => $tabParam, $pageParamName => $i]));
        echo "<li class=\"page-item {$active}\"><a class=\"page-link\" href=\"{$url}\">{$i}</a></li>";
    }

    // Next
    $nextPage = min($totalPages, $currentPage + 1);
    $nextUrl = '?' . http_build_query(array_merge($extraParams, ['tab' => $tabParam, $pageParamName => $nextPage]));
    $disabledNext = ($currentPage >= $totalPages) ? 'disabled' : '';
    echo "<li class=\"page-item {$disabledNext}\"><a class=\"page-link\" href=\"{$nextUrl}\">Weiter &raquo;</a></li>";

    echo '</ul></nav>';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Application Container Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; }
        .container-card { margin-bottom: 1rem; border-left: 4px solid #0d6efd; }
        .container-card-predefined { margin-bottom: 1rem; border-left: 4px solid #6c757d; }

        /* Verhindert das Verrutschen der Spalten über verschiedene Cards hinweg */
        .table-fixed {
            table-layout: fixed;
            width: 100%;
        }

        .table-fixed th,
        .table-fixed td {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
    </style>
</head>
<body class="py-4">
<div class="container-fluid px-4">
    <h1 class="mb-4">Application Container Dashboard</h1>

    <!-- Nav-Tabs -->
    <ul class="nav nav-pills mb-3" id="containerTab" role="tablist">
        <li class="nav-item">
            <button class="nav-link <?= $activeTab === 'cloud' ? 'active' : '' ?>" id="cloud-tab" data-bs-toggle="tab" data-bs-target="#cloud" type="button">
                Cloud Container (cloud-container.txt) <span class="badge bg-primary ms-1"><?= $totalCloud ?></span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $activeTab === 'predefined' ? 'active' : '' ?>" id="predefined-tab" data-bs-toggle="tab" data-bs-target="#predefined" type="button">
                Predefined Container (predefined.xml) <span class="badge bg-secondary ms-1"><?= $totalPredefined ?></span>
            </button>
        </li>
    </ul>

    <!-- Suchfeld unter den Tabs -->
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="GET" action="" class="row g-2 align-items-center">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
                <div class="col-md-10">
                    <input type="text" name="q" class="form-control" placeholder="Container-Name oder ID filtern..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100">Suchen</button>
                    <?php if ($search !== ''): ?>
                        <a href="?tab=<?= htmlspecialchars($activeTab) ?>" class="btn btn-outline-secondary">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="tab-content" id="containerTabContent">
        <!-- BEREICH 1: Cloud Container -->
        <div class="tab-pane fade <?= $activeTab === 'cloud' ? 'show active' : '' ?>" id="cloud" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="text-muted">Zeige Container <?= $totalCloud > 0 ? $offsetCloud + 1 : 0 ?> bis <?= min($offsetCloud + $perPage, $totalCloud) ?> von insgesamt <?= $totalCloud ?> Treffern</span>
            </div>

            <!-- Paging Oben -->
            <?php renderPagination($pageCloud, $totalPagesCloud, 'cloud', 'page_cloud', ['q' => $search, 'page_predefined' => $pagePredefined]); ?>

            <?php foreach ($cloudContainers as $container): ?>
                <?php $apps = getAppsForContainer($pdo, $container['name']); ?>
                <div class="card container-card shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <div>
                            <span class="fs-5 fw-bold text-primary"><?= htmlspecialchars($container['name']) ?></span>
                            <span class="badge bg-dark ms-2">ID: <?= htmlspecialchars($container['id']) ?></span>
                        </div>
                        <span class="badge bg-light text-dark border">
                            Verknüpfte Apps: <?= count($apps) ?>
                        </span>
                    </div>
                    <?php renderAppTable($apps); ?>
                </div>
            <?php endforeach; ?>

            <!-- Paging Unten -->
            <?php renderPagination($pageCloud, $totalPagesCloud, 'cloud', 'page_cloud', ['q' => $search, 'page_predefined' => $pagePredefined]); ?>
        </div>

        <!-- BEREICH 2: Predefined Container -->
        <div class="tab-pane fade <?= $activeTab === 'predefined' ? 'show active' : '' ?>" id="predefined" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="text-muted">Zeige Container <?= $totalPredefined > 0 ? $offsetPredefined + 1 : 0 ?> bis <?= min($offsetPredefined + $perPage, $totalPredefined) ?> von insgesamt <?= $totalPredefined ?> Treffern</span>
            </div>

            <!-- Paging Oben -->
            <?php renderPagination($pagePredefined, $totalPagesPredefined, 'predefined', 'page_predefined', ['q' => $search, 'page_cloud' => $pageCloud]); ?>

            <?php foreach ($predefinedContainers as $container): ?>
                <?php $apps = getAppsForContainer($pdo, $container['name']); ?>
                <div class="card container-card-predefined shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <div>
                            <span class="fs-5 fw-bold text-secondary"><?= htmlspecialchars($container['name']) ?></span>
                            <span class="badge bg-dark ms-2">ID: <?= htmlspecialchars($container['id']) ?></span>
                        </div>
                        <span class="badge bg-light text-dark border">
                            Verknüpfte Apps: <?= count($apps) ?>
                        </span>
                    </div>
                    <?php renderAppTable($apps); ?>
                </div>
            <?php endforeach; ?>

            <!-- Paging Unten -->
            <?php renderPagination($pagePredefined, $totalPagesPredefined, 'predefined', 'page_predefined', ['q' => $search, 'page_cloud' => $pageCloud]); ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>