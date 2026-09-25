<?php
header('Content-Type: application/xml; charset=utf-8');

// 1. Parameter auslesen
$cmd = $_GET['cmd'] ?? $_POST['cmd'] ?? '';

if (empty($cmd)) {
    http_response_code(400);
    echo '<?xml version="1.0" encoding="UTF-8"?><response status="error" code="400"><msg><line>Missing cmd parameter</line></msg></response>';
    exit();
}

$txtFile = __DIR__ . '/../cloud-appid.txt';

if (!file_exists($txtFile)) {
    http_response_code(500);
    echo '<?xml version="1.0" encoding="UTF-8"?><response status="error" code="500"><msg><line>cloud-appid.txt not found</line></msg></response>';
    exit();
}

// 2. Prüfung auf "ALL" Abfrage
$isAllQuery = preg_match('/<application>\s*<all\s*\/?>/i', $cmd);

if ($isAllQuery) {
    $lines = file($txtFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    // DOMDocument für hübsch formatiertes XML initialisieren
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true; // Sorgt für Zeilenumbrüche und Einrückungen

    $responseNode = $dom->createElement('response');
    $responseNode->setAttribute('status', 'success');
    $dom->appendChild($responseNode);

    $resultNode = $dom->createElement('result');
    $responseNode->appendChild($resultNode);

    foreach ($lines as $line) {
        $line = trim($line);

        // Header und Trennlinien überspringen
        if (strpos($line, 'Id') === 0 || strpos($line, '---') === 0) {
            continue;
        }

        // Spalten anhand von 2 oder mehr Leerzeichen trennen
        $columns = preg_split('/\s{2,}/', $line);

        if (count($columns) >= 5) {
            $id            = trim($columns[0]);
            $name          = trim($columns[1]);
            $receivingTime = trim($columns[2]);
            $taskId        = trim($columns[3]);
            $xmlHashcode   = trim($columns[4]);

            // <entry name="..."> Node bauen
            $entry = $dom->createElement('entry');
            $entry->setAttribute('name', $name);

            $entry->appendChild($dom->createElement('id', $id));
            $entry->appendChild($dom->createElement('receiving-time', $receivingTime));
            $entry->appendChild($dom->createElement('task-id', $taskId));
            $entry->appendChild($dom->createElement('xml-hashcode', $xmlHashcode));

            $resultNode->appendChild($entry);
        }
    }

    http_response_code(200);
    echo $dom->saveXML();
    exit();
}

// 3. Einzelabfrage (Name -> ID.xml)
$appName = '';
if (preg_match('/<application>(.*?)<\/application>/i', $cmd, $matches)) {
    $appName = trim($matches[1]);
}

if (empty($appName)) {
    http_response_code(400);
    echo '<?xml version="1.0" encoding="UTF-8"?><response status="error" code="400"><msg><line>Could not parse application name</line></msg></response>';
    exit();
}

// Index laden & Mappen
$nameToIdMap = [];
$lines = file($txtFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $line) {
    $line = trim($line);
    if (strpos($line, 'Id') === 0 || strpos($line, '---') === 0) continue;
    $columns = preg_split('/\s{2,}/', $line);
    if (isset($columns[0]) && isset($columns[1])) {
        $nameToIdMap[trim($columns[1])] = trim($columns[0]);
    }
}

if (!isset($nameToIdMap[$appName])) {
    http_response_code(404);
    echo '<?xml version="1.0" encoding="UTF-8"?><response status="error" code="404"><msg><line>Application "' . htmlspecialchars($appName) . '" not found</line></msg></response>';
    exit();
}

$appId = $nameToIdMap[$appName];
$filePath = __DIR__ . '/../data/' . $appId . '.xml';

if (file_exists($filePath)) {
    // Einzelne XML-Datei ebenfalls hübsch formatiert ausgeben
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    if (@$dom->load($filePath)) {
        http_response_code(200);
        echo $dom->saveXML();
    } else {
        http_response_code(200);
        echo file_get_contents($filePath);
    }
} else {
    http_response_code(404);
    echo '<?xml version="1.0" encoding="UTF-8"?><response status="error" code="404"><msg><line>XML file for ID ' . htmlspecialchars($appId) . ' missing</line></msg></response>';
}