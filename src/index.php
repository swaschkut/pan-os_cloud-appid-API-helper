<?php
header('Content-Type: application/xml; charset=utf-8');

ini_set('memory_limit', '512M');
set_time_limit(300);

// 1. Parameter auslesen
$cmd = $_GET['cmd'] ?? $_POST['cmd'] ?? '';

if (empty($cmd)) {
    http_response_code(400);
    echo '<?xml version="1.0" encoding="UTF-8"?><response status="error" code="400"><msg><line>Missing cmd parameter</line></msg></response>';
    exit();
}

$dbFile = __DIR__ . '/../cloud_appid.db';

if (!file_exists($dbFile)) {
    http_response_code(500);
    echo '<?xml version="1.0" encoding="UTF-8"?><response status="error" code="500"><msg><line>Database cloud_appid.db not found</line></msg></response>';
    exit();
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo '<?xml version="1.0" encoding="UTF-8"?><response status="error" code="500"><msg><line>Database connection failed</line></msg></response>';
    exit();
}

// 2. Erfassung der Suchkriterien & Paging-Parameter
$searchTerm = '';
if (preg_match('/<application>(.*?)<\/application>/i', $cmd, $matches)) {
    $searchTerm = trim($matches[1]);
}

$isAllQuery = (
    empty($searchTerm) ||
    strtolower($searchTerm) === 'all' ||
    stripos($cmd, '<all>') !== false ||
    stripos($cmd, '<all/>') !== false ||
    stripos($cmd, '<all></all>') !== false
);

$cleanSearch = $isAllQuery ? '' : trim(strip_tags($searchTerm));

// Paging Parameter extrahieren (Default: Start bei 1, Limit 2000)
$position = 1;
if (preg_match('/<position>(\d+)<\/position>/i', $cmd, $posMatches)) {
    $position = max(1, (int)$posMatches[1]);
}

$limit = 2000; // Standard Limit
if (preg_match('/<limit>(\d+)<\/limit>/i', $cmd, $limMatches)) {
    $limit = min(10000, max(1, (int)$limMatches[1]));
}
// SQLite OFFSET berechnen (Position 1 -> Offset 0)
$offset = $position - 1;

$isDataSummaryQuery = (stripos($cmd, '<cloud-app-data>') !== false);
$isWildcardSearch    = (!$isAllQuery && !empty($cleanSearch) && (strpos($cleanSearch, '%') !== false || strpos($cleanSearch, '*') !== false));

// =========================================================================
// FALL 1: INDEX-ÜBERSICHT (<cloud-app-data> im Command)
// =========================================================================
if ($isDataSummaryQuery) {

    // Gesamtzahl (Total) ermitteln
    if ($isWildcardSearch) {
        $sqlPattern = str_replace(['%', '*'], '%', $cleanSearch);
        $countStmt = $db->prepare("SELECT COUNT(*) FROM cloud_appids WHERE name LIKE :pattern");
        $countStmt->execute([':pattern' => $sqlPattern]);
        $totalCount = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("SELECT id, name, receiving_time, task_id, xml_hashcode FROM cloud_appids WHERE name LIKE :pattern ORDER BY id ASC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':pattern', $sqlPattern, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $totalCount = (int)$db->query("SELECT COUNT(*) FROM cloud_appids")->fetchColumn();

        $stmt = $db->prepare("SELECT id, name, receiving_time, task_id, xml_hashcode FROM cloud_appids ORDER BY id ASC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $returnedCount = count($rows);

    $rangeStart = $returnedCount > 0 ? $position : 0;
    $rangeEnd   = $returnedCount > 0 ? ($position + $returnedCount - 1) : 0;

    http_response_code(200);
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<response status="success">' . "\n";
    echo '  <result total-count="' . $totalCount . '" range-start="' . $rangeStart . '" range-end="' . $rangeEnd . '" count="' . $returnedCount . '">' . "\n";

    foreach ($rows as $row) {
        echo '    <entry name="' . htmlspecialchars($row['name'] ?? '', ENT_XML1) . '">' . "\n";
        echo '      <id>' . htmlspecialchars($row['id'] ?? '', ENT_XML1) . '</id>' . "\n";
        echo '      <receiving-time>' . htmlspecialchars($row['receiving_time'] ?? '', ENT_XML1) . '</receiving-time>' . "\n";
        echo '      <task-id>' . htmlspecialchars($row['task_id'] ?? '', ENT_XML1) . '</task-id>' . "\n";
        echo '      <xml-hashcode>' . htmlspecialchars($row['xml_hashcode'] ?? '', ENT_XML1) . '</xml-hashcode>' . "\n";
        echo '    </entry>' . "\n";
    }

    echo '  </result>' . "\n";
    echo '</response>';
    exit();
}

// =========================================================================
// FALL 2: DETAIL-ABFRAGE (XML Content)
// =========================================================================

if ($isAllQuery) {
    $totalCount = (int)$db->query("SELECT COUNT(*) FROM cloud_appids WHERE xml_content IS NOT NULL AND xml_content != ''")->fetchColumn();

    $stmt = $db->prepare("SELECT xml_content FROM cloud_appids WHERE xml_content IS NOT NULL AND xml_content != '' ORDER BY id ASC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
} elseif ($isWildcardSearch) {
    $sqlPattern = str_replace(['%', '*'], '%', $cleanSearch);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM cloud_appids WHERE name LIKE :pattern AND xml_content IS NOT NULL AND xml_content != ''");
    $countStmt->execute([':pattern' => $sqlPattern]);
    $totalCount = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT xml_content FROM cloud_appids WHERE name LIKE :pattern AND xml_content IS NOT NULL AND xml_content != '' ORDER BY id ASC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':pattern', $sqlPattern, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    // Einzeltreffer
    $stmt = $db->prepare("SELECT xml_content FROM cloud_appids WHERE name = :name AND xml_content IS NOT NULL AND xml_content != ''");
    $stmt->execute([':name' => $cleanSearch]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($rows)) {
        http_response_code(404);
        echo '<?xml version="1.0" encoding="UTF-8"?><response status="error" code="404"><msg><line>No applications found matching "' . htmlspecialchars($cleanSearch) . '"</line></msg></response>';
        exit();
    }

    http_response_code(200);
    echo $rows[0];
    exit();
}

$rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
$returnedCount = count($rows);

if (empty($rows)) {
    http_response_code(404);
    echo '<?xml version="1.0" encoding="UTF-8"?><response status="error" code="404"><msg><line>No applications found</line></msg></response>';
    exit();
}

$rangeStart = $returnedCount > 0 ? $position : 0;
$rangeEnd   = $returnedCount > 0 ? ($position + $returnedCount - 1) : 0;

http_response_code(200);
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<response status="success">' . "\n";
echo '  <result total-count="' . $totalCount . '" range-start="' . $rangeStart . '" range-end="' . $rangeEnd . '" count="' . $returnedCount . '">' . "\n";

foreach ($rows as $xmlContent) {
    if (preg_match('/<entry\b[^>]*>.*?<\/entry>/s', $xmlContent, $entryMatches)) {
        echo "    " . trim($entryMatches[0]) . "\n";
    }
}

echo '  </result>' . "\n";
echo '</response>';