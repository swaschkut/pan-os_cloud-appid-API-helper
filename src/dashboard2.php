<?php

$dbPath = __DIR__ . '/../cloud_appid.db';

if (!file_exists($dbPath)) {
    die("Database cloud_appid.db not found!");
}

$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Pagination Configuration
$perPage = 50; // Number of items per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// 2. Process Filter Parameters
$searchName      = trim($_GET['name'] ?? '');
$selectedCat     = $_GET['category'] ?? '';
$selectedNewCat  = $_GET['new_category'] ?? '';
$selectedSubcat  = $_GET['subcategory'] ?? '';
$selectedTech    = $_GET['technology'] ?? '';
$selectedContainer = $_GET['container'] ?? '';
$searchTag       = trim($_GET['tag'] ?? '');
$selectedRisk    = $_GET['risk'] ?? '';
$selectedSaas    = $_GET['is_saas'] ?? '';

// Retrieve Distinct Options for Dropdowns
$categories    = $pdo->query("SELECT DISTINCT category FROM cloud_appids WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$newCategories = $pdo->query("SELECT DISTINCT new_category FROM cloud_appids WHERE new_category IS NOT NULL AND new_category != '' ORDER BY new_category")->fetchAll(PDO::FETCH_COLUMN);
$subcategories = $pdo->query("SELECT DISTINCT subcategory FROM cloud_appids WHERE subcategory IS NOT NULL AND subcategory != '' ORDER BY subcategory")->fetchAll(PDO::FETCH_COLUMN);
$technologies  = $pdo->query("SELECT DISTINCT technology FROM cloud_appids WHERE technology IS NOT NULL AND technology != '' ORDER BY technology")->fetchAll(PDO::FETCH_COLUMN);
$containers    = $pdo->query("SELECT DISTINCT application_container FROM cloud_appids WHERE application_container IS NOT NULL AND application_container != '' ORDER BY application_container")->fetchAll(PDO::FETCH_COLUMN);

// Total count of all objects in DB (without filters)
$totalObjects = $pdo->query("SELECT COUNT(*) FROM cloud_appids")->fetchColumn();

// 3. Build SQL WHERE Conditions
$where = [];
$params = [];

if ($searchName !== '') {
    $where[] = "LOWER(name) LIKE LOWER(:name)";
    $params[':name'] = '%' . $searchName . '%';
}
if ($selectedCat !== '') {
    $where[] = "category = :category";
    $params[':category'] = $selectedCat;
}
if ($selectedNewCat !== '') {
    $where[] = "new_category = :new_category";
    $params[':new_category'] = $selectedNewCat;
}
if ($selectedSubcat !== '') {
    $where[] = "subcategory = :subcategory";
    $params[':subcategory'] = $selectedSubcat;
}
if ($selectedTech !== '') {
    $where[] = "technology = :technology";
    $params[':technology'] = $selectedTech;
}
if ($selectedContainer !== '') {
    $where[] = "application_container = :container";
    $params[':container'] = $selectedContainer;
}
if ($searchTag !== '') {
    $where[] = "LOWER(tags) LIKE LOWER(:tag)";
    $params[':tag'] = '%' . $searchTag . '%';
}
if ($selectedRisk !== '') {
    $where[] = "risk = :risk";
    $params[':risk'] = (int)$selectedRisk;
}
if ($selectedSaas !== '') {
    $where[] = "is_saas = :is_saas";
    $params[':is_saas'] = (int)$selectedSaas;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// 4. Get Filtered Total Count
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM cloud_appids $whereClause");
$stmtCount->execute($params);
$filteredObjects = (int)$stmtCount->fetchColumn();

// 5. Pagination Calculation (OFFSET & LIMIT)
$totalPages = max(1, ceil($filteredObjects / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// 6. Fetch Records for Current Page
$sql = "SELECT id, name, category, new_category, subcategory, technology, risk, is_saas, 
               tags, default_ports, use_applications, tunnel_applications, 
               create_date, last_update_date, application_container 
        FROM cloud_appids $whereClause 
        ORDER BY name ASC 
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$appids = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function to build URL parameters for navigation
function buildUrl($newPage) {
    $queryParams = $_GET;
    $queryParams['page'] = $newPage;
    return '?' . http_build_query($queryParams);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cloud App-ID Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <style>
        .badge-list .badge { margin-right: 3px; margin-bottom: 3px; }
        .text-small { font-size: 0.85rem; }
    </style>
</head>
<body class="bg-light p-4">

<div class="container-fluid">
    <!-- Header with Counters -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 m-0">Cloud App-ID Dashboard</h1>
        <div class="bg-white border rounded p-2 px-3 shadow-sm">
            <span class="fw-bold">Filtered:</span>
            <span class="badge bg-primary fs-6"><?= number_format($filteredObjects, 0, ',', '.') ?></span>
            <span class="text-muted">out of <?= number_format($totalObjects, 0, ',', '.') ?> total items</span>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header fw-bold bg-white">Filters & Search</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3 col-lg-2">
                    <label class="form-label fw-semibold">Name</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. oracle..." value="<?= htmlspecialchars($searchName) ?>">
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label fw-semibold">Category</label>
                    <select name="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $selectedCat === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label fw-semibold">New Category</label>
                    <select name="new_category" class="form-select">
                        <option value="">All New Categories</option>
                        <?php foreach ($newCategories as $ncat): ?>
                            <option value="<?= htmlspecialchars($ncat) ?>" <?= $selectedNewCat === $ncat ? 'selected' : '' ?>><?= htmlspecialchars($ncat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label fw-semibold">Subcategory</label>
                    <select name="subcategory" class="form-select">
                        <option value="">All Subcategories</option>
                        <?php foreach ($subcategories as $subcat): ?>
                            <option value="<?= htmlspecialchars($subcat) ?>" <?= $selectedSubcat === $subcat ? 'selected' : '' ?>><?= htmlspecialchars($subcat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label fw-semibold">Technology</label>
                    <select name="technology" class="form-select">
                        <option value="">All Technologies</option>
                        <?php foreach ($technologies as $tech): ?>
                            <option value="<?= htmlspecialchars($tech) ?>" <?= $selectedTech === $tech ? 'selected' : '' ?>><?= htmlspecialchars($tech) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label fw-semibold">Container</label>
                    <select name="container" class="form-select">
                        <option value="">All Containers</option>
                        <?php foreach ($containers as $cnt): ?>
                            <option value="<?= htmlspecialchars($cnt) ?>" <?= $selectedContainer === $cnt ? 'selected' : '' ?>><?= htmlspecialchars($cnt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label fw-semibold">Tag</label>
                    <input type="text" name="tag" class="form-control" placeholder="e.g. saas-app..." value="<?= htmlspecialchars($searchTag) ?>">
                </div>
                <div class="col-md-3 col-lg-1">
                    <label class="form-label fw-semibold">Risk (1-5)</label>
                    <select name="risk" class="form-select">
                        <option value="">All</option>
                        <?php for ($r = 1; $r <= 5; $r++): ?>
                            <option value="<?= $r ?>" <?= $selectedRisk === (string)$r ? 'selected' : '' ?>><?= $r ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-1">
                    <label class="form-label fw-semibold">SaaS App</label>
                    <select name="is_saas" class="form-select">
                        <option value="">All</option>
                        <option value="1" <?= $selectedSaas === '1' ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= $selectedSaas === '0' ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="col-md-6 col-lg-2 d-flex align-items-end ms-auto">
                    <button type="submit" class="btn btn-primary w-100 me-2">Apply</button>
                    <a href="dashboard.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Results Table -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="table-responsive">
                <table id="appidTable" class="table table-striped table-hover align-middle text-small">
                    <thead class="table-dark">
                    <tr>
                        <th>Name</th>
                        <th>Category</th>
                        <th>New Category</th>
                        <th>Subcategory</th>
                        <th>Technology</th>
                        <th>Tags</th>
                        <th>Ports</th>
                        <th>Use Apps</th>
                        <th>Tunnel Apps</th>
                        <th>Container</th>
                        <th>Dates (Created / Updated)</th>
                        <th>Risk</th>
                        <th>SaaS</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($appids)): ?>
                        <tr>
                            <td colspan="14" class="text-center py-4 text-muted">No records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($appids as $row): ?>
                            <tr>
                                <td class="fw-bold text-primary"><?= htmlspecialchars($row['name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['category'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['new_category'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['subcategory'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['technology'] ?? '-') ?></td>

                                <td class="badge-list">
                                    <?php
                                    $tags = json_decode($row['tags'] ?? '[]', true);
                                    if (!empty($tags) && is_array($tags)):
                                        foreach ($tags as $tag): ?>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($tag) ?></span>
                                        <?php endforeach;
                                    else: echo '-'; endif;
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    $ports = json_decode($row['default_ports'] ?? '[]', true);
                                    echo htmlspecialchars(is_array($ports) ? implode(', ', $ports) : '-');
                                    ?>
                                </td>

                                <td class="badge-list">
                                    <?php
                                    $useApps = json_decode($row['use_applications'] ?? '[]', true);
                                    if (!empty($useApps) && is_array($useApps)):
                                        foreach ($useApps as $app): ?>
                                            <span class="badge bg-info text-dark"><?= htmlspecialchars($app) ?></span>
                                        <?php endforeach;
                                    else: echo '-'; endif;
                                    ?>
                                </td>

                                <td class="badge-list">
                                    <?php
                                    $tunnelApps = json_decode($row['tunnel_applications'] ?? '[]', true);
                                    if (!empty($tunnelApps) && is_array($tunnelApps)):
                                        foreach ($tunnelApps as $app): ?>
                                            <span class="badge bg-warning text-dark"><?= htmlspecialchars($app) ?></span>
                                        <?php endforeach;
                                    else: echo '-'; endif;
                                    ?>
                                </td>

                                <td><?= htmlspecialchars($row['application_container'] ?? '-') ?></td>

                                <td>
                                    <div class="text-nowrap"><strong>C:</strong> <?= htmlspecialchars($row['create_date'] ?? '-') ?></div>
                                    <div class="text-nowrap"><strong>U:</strong> <?= htmlspecialchars($row['last_update_date'] ?? '-') ?></div>
                                </td>

                                <td>
                                    <span class="badge bg-<?= $row['risk'] >= 4 ? 'danger' : ($row['risk'] >= 3 ? 'warning' : 'success') ?>">
                                        Risk <?= $row['risk'] ?>
                                    </span>
                                </td>
                                <td><?= $row['is_saas'] ? '<span class="badge bg-success">SaaS</span>' : '-' ?></td>

                                <td>
                                    <a href="index.php?cmd=<?= urlencode('<show><cloud-appid><application>' . ($row['name'] ?? '') . '</application></cloud-appid></show>') ?>"
                                       target="_blank"
                                       class="btn btn-sm btn-outline-primary text-nowrap">
                                        XML API
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Navigation -->
            <?php if ($totalPages > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="text-muted text-small">
                        Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $filteredObjects) ?> of <?= number_format($filteredObjects, 0, ',', '.') ?> entries
                    </div>
                    <nav>
                        <ul class="pagination pagination-sm m-0">
                            <!-- First & Previous Page -->
                            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= buildUrl(1) ?>">&laquo; First</a>
                            </li>
                            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= buildUrl($page - 1) ?>">Previous</a>
                            </li>

                            <!-- Dynamic Page Numbers (Max 5 Visible Buttons) -->
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage   = min($totalPages, $page + 2);

                            for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= buildUrl($i) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>

                            <!-- Next & Last Page -->
                            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= buildUrl($page + 1) ?>">Next</a>
                            </li>
                            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= buildUrl($totalPages) ?>">Last &raquo;</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        $('#appidTable').DataTable({
            "paging": false,
            "searching": false,
            "info": false,
            "scrollX": true
        });
    });
</script>
</body>
</html>