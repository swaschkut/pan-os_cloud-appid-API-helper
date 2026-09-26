<?php

$dbPath = __DIR__ . '/../cloud_appid.db';

if (!file_exists($dbPath)) {
    die("Datenbank cloud_appid.db nicht gefunden!");
}

$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Gesamtzahl aller Objekte in der DB (ohne Filter)
$totalObjects =$pdo->query("SELECT COUNT(*) FROM cloud_appids")->fetchColumn();

// Filtermöglichkeiten auslesen
$categories =$pdo->query("SELECT DISTINCT category FROM cloud_appids WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$technologies =$pdo->query("SELECT DISTINCT technology FROM cloud_appids WHERE technology IS NOT NULL AND technology != '' ORDER BY technology")->fetchAll(PDO::FETCH_COLUMN);

// Filter-Parameter verarbeiten
$searchName   =$_GET['name'] ?? '';
$selectedCat  =$_GET['category'] ?? '';
$selectedTech =$_GET['technology'] ?? '';
$selectedRisk =$_GET['risk'] ?? '';
$selectedSaas =$_GET['is_saas'] ?? '';

// SQL Query aufbauen
$where = [];$params = [];

if ($searchName !== '') {$where[] = "(name LIKE :name OR ottawa_name LIKE :name)";
    $params[':name'] = '\%' .$searchName . '%';
}
if ($selectedCat !== '') {$where[] = "category = :category";
    $params[':category'] =$selectedCat;
}
if ($selectedTech !== '') {$where[] = "technology = :technology";
    $params[':technology'] =$selectedTech;
}
if ($selectedRisk !== '') {$where[] = "risk = :risk";
    $params[':risk'] = (int)$selectedRisk;
}
if ($selectedSaas !== '') {$where[] = "is_saas = :is_saas";
    $params[':is_saas'] = (int)$selectedSaas;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Gefilterte Anzahl abfragen
$stmtCount =$pdo->prepare("SELECT COUNT(*) FROM cloud_appids $whereClause");
$stmtCount->execute($params);
$filteredObjects =$stmtCount->fetchColumn();

// Daten abfragen
$sql = "SELECT id, name, ottawa_name, category, new_category, subcategory, technology, risk, is_saas, 
               tags, default_ports, use_applications, tunnel_applications, 
               create_date, last_update_date, application_container, xml_content 
        FROM cloud_appids $whereClause ORDER BY name ASC LIMIT 2000";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$appids =$stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
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
    <!-- Header mit Objekt-Zähler oben rechts -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 m-0">Cloud App-ID Dashboard</h1>
        <div class="bg-white border rounded p-2 px-3 shadow-sm">
            <span class="fw-bold">Anzeige:</span>
            <span class="badge bg-primary fs-6"><?= number_format($filteredObjects, 0, ',', '.') ?></span>
            <span class="text-muted">von insgesamt <?= number_format($totalObjects, 0, ',', '.') ?> Objekten</span>
        </div>
    </div>

    <!-- Filter Formular -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header fw-bold bg-white">Filter & Suche</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Name / Ottawa Name</label>
                    <input type="text" name="name" class="form-control" placeholder="z.B. oracle..." value="<?= htmlspecialchars($searchName) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Kategorie</label>
                    <select name="category" class="form-select">
                        <option value="">Alle Kategorien</option>
                        <?php foreach ($categories as$cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $selectedCat === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Technologie</label>
                    <select name="technology" class="form-select">
                        <option value="">Alle Technologien</option>
                        <?php foreach ($technologies as$tech): ?>
                            <option value="<?= htmlspecialchars($tech) ?>" <?= $selectedTech === $tech ? 'selected' : '' ?>><?= htmlspecialchars($tech) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Risiko (1-5)</label>
                    <select name="risk" class="form-select">
                        <option value="">Alle</option>
                        <?php for ($r = 1; $r <= 5; $r++): ?>
                            <option value="<?= $r ?>" <?= $selectedRisk === (string)$r ? 'selected' : '' ?>><?= $r ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">SaaS App</label>
                    <select name="is_saas" class="form-select">
                        <option value="">Alle</option>
                        <option value="1" <?= $selectedSaas === '1' ? 'selected' : '' ?>>Ja</option>
                        <option value="0" <?= $selectedSaas === '0' ? 'selected' : '' ?>>Nein</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 me-2">Filtern</button>
                    <a href="dashboard.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Ergebnistabelle -->
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table id="appidTable" class="table table-striped table-hover align-middle text-small">
                    <thead class="table-dark">
                    <tr>
                        <th>Name</th>
                        <th>Ottawa Name</th>
                        <th>Kategorie</th>
                        <th>Neue Kat.</th>
                        <th>Subkategorie</th>
                        <th>Technologie</th>
                        <th>Tags</th>
                        <th>Ports</th>
                        <th>Use Apps</th>
                        <th>Tunnel Apps</th>
                        <th>Container</th>
                        <th>Datum (Erstellt / Update)</th>
                        <th>Risiko</th>
                        <th>SaaS</th>
                        <th>Aktionen</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($appids as$row): ?>
                        <tr>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($row['name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['ottawa_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($row['category'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($row['new_category'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($row['subcategory'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($row['technology'] ?? '-') ?></td>

                            <!-- Tags -->
                            <td class="badge-list">
                                <?php
                                $tags = json_decode($row['tags'] ?? '[]', true);
                                if (!empty($tags) && is_array($tags)):
                                    foreach ($tags as$tag): ?>
                                        <span class="badge bg-secondary"><?= htmlspecialchars($tag) ?></span>
                                    <?php endforeach;
                                else: echo '-'; endif;
                                ?>
                            </td>

                            <!-- Ports -->
                            <td>
                                <?php
                                $ports = json_decode($row['default_ports'] ?? '[]', true);
                                echo htmlspecialchars(is_array($ports) ? implode(', ', $ports) : '-');
                                ?>
                            </td>

                            <!-- Use Applications -->
                            <td class="badge-list">
                                <?php
                                $useApps = json_decode($row['use_applications'] ?? '[]', true);
                                if (!empty($useApps) && is_array($useApps)):
                                    foreach ($useApps as$app): ?>
                                        <span class="badge bg-info text-dark"><?= htmlspecialchars($app) ?></span>
                                    <?php endforeach;
                                else: echo '-'; endif;
                                ?>
                            </td>

                            <!-- Tunnel Applications -->
                            <td class="badge-list">
                                <?php
                                $tunnelApps = json_decode($row['tunnel_applications'] ?? '[]', true);
                                if (!empty($tunnelApps) && is_array($tunnelApps)):
                                    foreach ($tunnelApps as$app): ?>
                                        <span class="badge bg-warning text-dark"><?= htmlspecialchars($app) ?></span>
                                    <?php endforeach;
                                else: echo '-'; endif;
                                ?>
                            </td>

                            <td><?= htmlspecialchars($row['application_container'] ?? '-') ?></td>

                            <!-- Datumsangaben -->
                            <td>
                                <div class="text-nowrap"><strong>C:</strong> <?= htmlspecialchars($row['create_date'] ?? '-') ?></div>
                                <div class="text-nowrap"><strong>U:</strong> <?= htmlspecialchars($row['last_update_date'] ?? '-') ?></div>
                            </td>

                            <td>
                                <span class="badge bg-<?= $row['risk'] >= 4 ? 'danger' : ($row['risk'] >= 3 ? 'warning' : 'success') ?>">
                                    Risiko <?= $row['risk'] ?>
                                </span>
                            </td>
                            <td><?= $row['is_saas'] ? '<span class="badge bg-success">SaaS</span>' : '-' ?></td>
                            <td>
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary view-xml-api text-nowrap"
                                        data-appname="<?= htmlspecialchars($row['name'] ?? '') ?>">
                                    XML API
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal zur XML-Anzeige -->
<div class="modal fade" id="xmlModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="xmlModalTitle">XML Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre class="bg-dark text-light p-3 rounded"><code id="xmlContent"></code></pre>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        // DataTables-Initialisierung
        $('#appidTable').DataTable({
            "pageLength": 25,
            "scrollX": true,
            "language": {
                "search": "Schnellsuche in geladenen Daten:"
            }
        });

        const xmlModal = new bootstrap.Modal(document.getElementById('xmlModal'));

        // Klick-Event für den XML-Button
        $('#appidTable').on('click', '.view-xml-api', function() {
            const appName = $(this).data('appname');
            const cmd = `<show><cloud-appid><application>${appName}</application></cloud-appid></show>`;

            $('#xmlModalTitle').text('XML Details: ' + appName);
            $('#xmlContent').text('Lade XML über API...');
            xmlModal.show();

            $.ajax({
                url: 'index.php',
                type: 'GET',
                data: { cmd: cmd },
                dataType: 'text',
                success: function(response) {
                    $('#xmlContent').text(response);
                },
                error: function() {
                    $('#xmlContent').text('Fehler: XML konnte nicht über die API geladen werden.');
                }
            });
        });
    });
</script>
</body>
</html>