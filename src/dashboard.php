<?php

$dbPath = __DIR__ . '/../cloud_appid.db';

if (!file_exists($dbPath)) {
    die("Datenbank cloud_appid.db nicht gefunden!");
}

$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Filtermöglichkeiten auslesen
$categories = $pdo->query("SELECT DISTINCT category FROM cloud_appids WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$technologies = $pdo->query("SELECT DISTINCT technology FROM cloud_appids WHERE technology IS NOT NULL AND technology != '' ORDER BY technology")->fetchAll(PDO::FETCH_COLUMN);

// Filter-Parameter verarbeiten
$selectedCategory = $_GET['category'] ?? '';
$selectedTech = $_GET['technology'] ?? '';
$selectedRisk = $_GET['risk'] ?? '';
$selectedSaas = $_GET['is_saas'] ?? '';

// SQL Query aufbauen
$where = [];
$params = [];

if ($selectedCategory !== '') {
    $where[] = "category = :category";
    $params[':category'] = $selectedCategory;
}
if ($selectedTech !== '') {
    $where[] = "technology = :technology";
    $params[':technology'] = $selectedTech;
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
$stmt = $pdo->prepare("SELECT id, name, category, subcategory, technology, risk, is_saas, default_ports, xml_content FROM cloud_appids $whereClause ORDER BY name ASC LIMIT 1000");
$stmt->execute($params);
$appids = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Cloud App-ID Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
</head>
<body class="bg-light p-4">

<div class="container-fluid">
    <h1 class="mb-4">Cloud App-ID Dashboard</h1>

    <!-- Filter Formular -->
    <div class="card mb-4">
        <div class="card-header fw-bold">Filter & Suche</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Kategorie</label>
                    <select name="category" class="form-select">
                        <option value="">Alle Kategorien</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $selectedCategory === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Technologie</label>
                    <select name="technology" class="form-select">
                        <option value="">Alle Technologien</option>
                        <?php foreach ($technologies as $tech): ?>
                            <option value="<?= htmlspecialchars($tech) ?>" <?= $selectedTech === $tech ? 'selected' : '' ?>><?= htmlspecialchars($tech) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Risiko (1-5)</label>
                    <select name="risk" class="form-select">
                        <option value="">Alle</option>
                        <?php for ($r = 1; $r <= 5; $r++): ?>
                            <option value="<?= $r ?>" <?= $selectedRisk === (string)$r ? 'selected' : '' ?>><?= $r ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">SaaS App</label>
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
    <div class="card">
        <div class="card-body">
            <table id="appidTable" class="table table-striped table-hover">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Kategorie</th>
                    <th>Subkategorie</th>
                    <th>Technologie</th>
                    <th>Risiko</th>
                    <th>SaaS</th>
                    <th>Standard Ports</th>
                    <th>Aktionen</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($appids as $row): ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($row['name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['category'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['subcategory'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['technology'] ?? '') ?></td>
                        <td>
                                <span class="badge bg-<?= $row['risk'] >= 4 ? 'danger' : ($row['risk'] >= 3 ? 'warning' : 'success') ?>">
                                    Risiko <?= $row['risk'] ?>
                                </span>
                        </td>
                        <td><?= $row['is_saas'] ? '<span class="badge bg-info">SaaS</span>' : '-' ?></td>
                        <td>
                            <?php
                            $ports = json_decode($row['default_ports'] ?? '[]', true);
                            echo htmlspecialchars(implode(', ', $ports));
                            ?>
                        </td>
                        <td>
                            <a href="index.php?cmd=<?= urlencode('<show><cloud-appid><application>' . ($row['name'] ?? '') . '</application></cloud-appid></show>') ?>"
                               target="_blank"
                               class="btn btn-sm btn-outline-primary">
                                XML API
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal zur XML-Anzeige -->
<div class="modal fade" id="xmlModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">XML Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre><code id="xmlContent"></code></pre>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        // DataTables-Initialisierung
        const table = $('#appidTable').DataTable({
            "pageLength": 25,
            "language": {
                "search": "Schnellsuche in Tabelle:"
            }
        });

        const xmlModal = new bootstrap.Modal(document.getElementById('xmlModal'));

        // Klick-Event für den XML-Button
        $('#appidTable').on('click', '.view-xml-api', function() {
            const appName = $(this).data('appname');

            // Genau der Befehl, den deine index.php erwartet
            const cmd = `<show><cloud-appid><application>${appName}</application></cloud-appid></show>`;

            // Platzhalter anzeigen, während die API antwortet
            $('#xmlContent').text('Lade XML über API...');
            xmlModal.show();

            // API-Aufruf an deine index.php
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