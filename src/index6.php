<?php
header('Content-Type: application/xml; charset=utf-8');

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

// 2. Extrahiere den Suchbegriff
$searchTerm = '';
if (preg_match('/<application>(.*?)<\/application>/i', $cmd, $matches)) {
    $searchTerm = trim($matches[1]);
    if (preg_match('/<all>(.*?)<\/all>/i', $searchTerm, $allMatches)) {
        $searchTerm = trim($allMatches[1]);
    }
}

// Prüfe die Art der Anfrage
$isDataSummaryQuery = (stripos($cmd, '<cloud-app-data>') !== false);
$isWildcardSearch    = (strpos($searchTerm, '%') !== false || strpos($searchTerm, '*') !== false);
$isAllQuery          = (empty($searchTerm) || strtolower($searchTerm) === 'all' || stripos($searchTerm, '<all>') !== false);

$cleanSearch = strip_tags($searchTerm);

// =========================================================================
// FALL 1: INDEX-ÜBERSICHT (wenn <cloud-app-data> im Command enthalten ist)
// =========================================================================
if ($isDataSummaryQuery) {
    if ($isWildcardSearch) {
        $sqlPattern = str_replace(['%', '*'], '%', $cleanSearch);
        $stmt = $db->prepare("SELECT id, name, receiving_time, task_id, xml_hashcode FROM cloud_appids WHERE name LIKE :pattern");
        $stmt->execute([':pattern' => $sqlPattern]);
    } else {
        $stmt = $db->query("SELECT id, name, receiving_time, task_id, xml_hashcode FROM cloud_appids");
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $responseNode = $dom->createElement('response');
    $responseNode->setAttribute('status', 'success');
    $dom->appendChild($responseNode);

    $resultNode = $dom->createElement('result');
    $responseNode->appendChild($resultNode);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $entry = $dom->createElement('entry');
        $entry->setAttribute('name', $row['name']);
        $entry->appendChild($dom->createElement('id', (string)$row['id']));
        $entry->appendChild($dom->createElement('receiving-time', (string)$row['receiving_time']));
        $entry->appendChild($dom->createElement('task-id', (string)$row['task_id']));
        $entry->appendChild($dom->createElement('xml-hashcode', (string)$row['xml_hashcode']));
        $resultNode->appendChild($entry);
    }

    http_response_code(200);
    echo $dom->saveXML();
    exit();
}

// =========================================================================
// FALL 2: DETAIL-ABFRAGE (Liefert Inhalte aus der Spalte xml_content)
// =========================================================================

if ($isWildcardSearch || $isAllQuery) {
    if ($isAllQuery) {
        $stmt = $db->query("SELECT xml_content FROM cloud_appids WHERE xml_content IS NOT NULL");
    } else {
        $sqlPattern = str_replace(['%', '*'], '%', $cleanSearch);
        $stmt = $db->prepare("SELECT xml_content FROM cloud_appids WHERE name LIKE :pattern AND xml_content IS NOT NULL");
        $stmt->execute([':pattern' => $sqlPattern]);
    }
} else {
    $stmt = $db->prepare("SELECT xml_content FROM cloud_appids WHERE name = :name AND xml_content IS NOT NULL");
    $stmt->execute([':name' => $cleanSearch]);
}

$rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($rows)) {
    http_response_code(404);
    echo '<?xml version="1.0" encoding="UTF-8"?><response status="error" code="404"><msg><line>No applications found matching "' . htmlspecialchars($cleanSearch) . '"</line></msg></response>';
    exit();
}

// Wenn nur 1 Eintrag gematcht wurde -> Direkt den gespeicherten XML-Content ausgeben
if (count($rows) === 1) {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    if (@$dom->loadXML($rows[0])) {
        http_response_code(200);
        echo $dom->saveXML();
    } else {
        http_response_code(200);
        echo $rows[0];
    }
    exit();
}

// Wenn mehrere Eintrags-XMLs zusammengeführt werden müssen (bei %-Suchen)
$xmlOutput = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xmlOutput .= '<response status="success">' . "\n";
$xmlOutput .= '  <result>' . "\n";

foreach ($rows as $xmlContent) {
    if (preg_match('/<result>(.*?)<\/result>/s', $xmlContent, $m)) {
        $xmlOutput .= $m[1] . "\n";
    } elseif (preg_match('/<entry\b[^>]*>.*?<\/entry>/s', $xmlContent, $entryMatches)) {
        $xmlOutput .= "    " . $entryMatches[0] . "\n";
    }
}

$xmlOutput .= '  </result>' . "\n";
$xmlOutput .= '</response>';

$dom = new DOMDocument('1.0', 'UTF-8');
$dom->preserveWhiteSpace = false;
$dom->formatOutput = true;

if (@$dom->loadXML($xmlOutput)) {
    http_response_code(200);
    echo $dom->saveXML();
} else {
    http_response_code(200);
    echo $xmlOutput;
}