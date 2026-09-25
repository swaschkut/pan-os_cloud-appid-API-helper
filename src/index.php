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

// 2. Extrahiere den Such-String aus <application>...</application> oder <all>...</all>
$searchTerm = '';

if (preg_match('/<application>(.*?)<\/application>/i', $cmd, $matches)) {
    $searchTerm = trim($matches[1]);

    // Falls <all>%pattern%</all> verschachtelt ist
    if (preg_match('/<all>(.*?)<\/all>/i', $searchTerm, $allMatches)) {
        $searchTerm = trim($allMatches[1]);
    }
}

// 3. Entscheiden, ob es eine Wildcard- / Filter-Suche ist
$isWildcardSearch = (strpos($searchTerm, '%') !== false || strpos($searchTerm, '*') !== false);
$isAllQuery       = (empty($searchTerm) || strtolower($searchTerm) === 'all' || strtolower($searchTerm) === '<all></all>');

if ($isWildcardSearch || $isAllQuery) {

    // Wildcard % oder * in Regex-Pattern umwandeln
    $pattern = null;
    if ($isWildcardSearch) {
        // XML-Tags entfernen, falls <all>...</all> als String übergeben wurde
        $cleanSearch = strip_tags($searchTerm);
        $cleanSearch = str_replace(['<all>', '</all>'], '', $cleanSearch);

        // % und * durch .* ersetzen und Sonderzeichen maskieren
        $regex = str_replace(['%', '*'], '.*', preg_quote($cleanSearch, '/'));
        $regex = str_replace('\.\*', '.*', $regex); // Ausmaskierung für .* zurücknehmen
        $pattern = '/^' . $regex . '$/i';
    }

    $lines = file($txtFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $responseNode = $dom->createElement('response');
    $responseNode->setAttribute('status', 'success');
    $dom->appendChild($responseNode);

    $resultNode = $dom->createElement('result');
    $responseNode->appendChild($resultNode);

    foreach ($lines as $line) {
        $line = trim($line);

        if (strpos($line, 'Id') === 0 || strpos($line, '---') === 0) {
            continue;
        }

        $columns = preg_split('/\s{2,}/', $line);

        if (count($columns) >= 5) {
            $id            = trim($columns[0]);
            $name          = trim($columns[1]);
            $receivingTime = trim($columns[2]);
            $taskId        = trim($columns[3]);
            $xmlHashcode   = trim($columns[4]);

            // Falls Suchmuster aktiv ist -> Filtern
            if ($pattern !== null && !preg_match($pattern, $name)) {
                continue;
            }

            // <entry name="..."> Node erstellen
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

// 4. Einzelabfrage ohne Wildcards (Sucht exakten Namen -> liefert ID.xml)
$appName = strip_tags($searchTerm);

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