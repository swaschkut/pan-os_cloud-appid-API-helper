<?php
header('Content-Type: application/xml; charset=utf-8');

// Speichermitigation & Timeout für sehr große XML-Generierungen anheben
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

// 2. Erfassung der Suchkriterien / <all>-Abfrage
$searchTerm = '';
if (preg_match('/<application>(.*?)<\/application>/i', $cmd, $matches)) {
    $searchTerm = trim($matches[1]);
}

// Prüfe explizit auf <all>, <all/> oder leeren Inhalt im application-Tag
$isAllQuery = (
    empty($searchTerm) ||
    strtolower($searchTerm) === 'all' ||
    stripos($cmd, '<all>') !== false ||
    stripos($cmd, '<all/>') !== false ||
    stripos($cmd, '<all></all>') !== false
);

// Suchbegriff säubern (nur relevant, wenn keine ALL-Abfrage vorliegt)
$cleanSearch = $isAllQuery ? '' : trim(strip_tags($searchTerm));

// Art der Anfrage prüfen
$isDataSummaryQuery = (stripos($cmd, '<cloud-app-data>') !== false);
$isWildcardSearch    = (!$isAllQuery && !empty($cleanSearch) && (strpos($cleanSearch, '%') !== false || strpos($cleanSearch, '*') !== false));

// =========================================================================
// FALL 1: INDEX-ÜBERSICHT (<cloud-app-data> im Command enthalten)
// =========================================================================
if ($isDataSummaryQuery) {

    if ($isWildcardSearch) {
        $sqlPattern = str_replace(['%', '*'], '%', $cleanSearch);
        $stmt = $db->prepare("SELECT id, name, receiving_time, task_id, xml_hashcode FROM cloud_appids WHERE name LIKE :pattern ORDER BY id ASC");
        $stmt->execute([':pattern' => $sqlPattern]);
    } else {
        // Bei <all> oder leerer Suche: Alle Datensätze abfragen
        $stmt = $db->query("SELECT id, name, receiving_time, task_id, xml_hashcode FROM cloud_appids ORDER BY id ASC");
    }

    http_response_code(200);
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<response status="success">' . "\n";
    echo '  <result>' . "\n";

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
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
// FALL 2: DETAIL-ABFRAGE (Inhalte aus Spalte xml_content)
// =========================================================================

if ($isAllQuery) {
    // Alle XML-Einträge abfragen
    $stmt = $db->query("SELECT xml_content FROM cloud_appids WHERE xml_content IS NOT NULL AND xml_content != '' ORDER BY id ASC");
} elseif ($isWildcardSearch) {
    $sqlPattern = str_replace(['%', '*'], '%', $cleanSearch);
    $stmt = $db->prepare("SELECT xml_content FROM cloud_appids WHERE name LIKE :pattern AND xml_content IS NOT NULL AND xml_content != '' ORDER BY id ASC");
    $stmt->execute([':pattern' => $sqlPattern]);
} else {
    $stmt = $db->prepare("SELECT xml_content FROM cloud_appids WHERE name = :name AND xml_content IS NOT NULL AND xml_content != ''");
    $stmt->execute([':name' => $cleanSearch]);
}

$rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($rows)) {
    http_response_code(404);
    echo '<?xml version="1.0" encoding="UTF-8"?><response status="error" code="404"><msg><line>No applications found matching "' . htmlspecialchars($cleanSearch ?: 'all') . '"</line></msg></response>';
    exit();
}

// Wenn nur 1 Eintrag gematcht wurde -> Direkt ausgeben
if (count($rows) === 1) {
    http_response_code(200);
    echo $rows[0];
    exit();
}

// Wenn mehrere XML-Einträge zusammengeführt werden (z. B. bei ALL-Abfrage)
http_response_code(200);
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<response status="success">' . "\n";
echo '  <result>' . "\n";

foreach ($rows as $xmlContent) {
    // Isoliere gezielt die <entry ...>...</entry> Blöcke und ignoriere XML-Prologe & <result>-Tags
    if (preg_match('/<entry\b[^>]*>.*?<\/entry>/s', $xmlContent, $entryMatches)) {
        echo "    " . trim($entryMatches[0]) . "\n";
    }
}

echo '  </result>' . "\n";
echo '</response>';