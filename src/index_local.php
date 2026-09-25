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
$dataDir = __DIR__ . '/../data';

if (!file_exists($txtFile)) {
    http_response_code(500);
    echo '<?xml version="1.0" encoding="UTF-8"?><response status="error" code="500"><msg><line>cloud-appid.txt not found</line></msg></response>';
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

// Prüfen, ob explizit nach der Index-Übersicht (<cloud-app-data>) gefragt wird
$isDataSummaryQuery = (stripos($cmd, '<cloud-app-data>') !== false);

// Prüfen auf Wildcards oder ALL
$isWildcardSearch = (strpos($searchTerm, '%') !== false || strpos($searchTerm, '*') !== false);
$isAllQuery       = (empty($searchTerm) || strtolower($searchTerm) === 'all' || stripos($searchTerm, '<all>') !== false);


// =========================================================================
// FALL 1: INDEX-ÜBERSICHT (NUR wenn <cloud-app-data> explizit im CMD steht)
// =========================================================================
if ($isDataSummaryQuery) {
    $pattern = null;
    if ($isWildcardSearch) {
        $cleanSearch = strip_tags($searchTerm);
        $regex = str_replace(['%', '*'], '.*', preg_quote($cleanSearch, '/'));
        $regex = str_replace('\.\*', '.*', $regex);
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
        if (strpos($line, 'Id') === 0 || strpos($line, '---') === 0) continue;

        $columns = preg_split('/\s{2,}/', $line);
        if (count($columns) >= 5) {
            $id            = trim($columns[0]);
            $name          = trim($columns[1]);
            $receivingTime = trim($columns[2]);
            $taskId        = trim($columns[3]);
            $xmlHashcode   = trim($columns[4]);

            if ($pattern !== null && !preg_match($pattern, $name)) {
                continue;
            }

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


// =========================================================================
// FALL 2: DETAIL-ABFRAGEN (OHNE <cloud-app-data>) -> Baut XMLs aus data/*.xml
// =========================================================================

// A) Mehrere Detail-XMLs wegen Wildcard (%) oder ALL
if ($isWildcardSearch || $isAllQuery) {
    $cleanSearch = strip_tags($searchTerm);
    $regex = str_replace(['%', '*'], '.*', preg_quote($cleanSearch, '/'));
    $regex = str_replace('\.\*', '.*', $regex);
    $pattern = '/^' . $regex . '$/i';

    // Passende IDs über cloud-appid.txt ermitteln
    $lines = file($txtFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $matchedIds = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, 'Id') === 0 || strpos($line, '---') === 0) continue;
        $columns = preg_split('/\s{2,}/', $line);
        if (isset($columns[0]) && isset($columns[1])) {
            $id   = trim($columns[0]);
            $name = trim($columns[1]);

            if ($isAllQuery || preg_match($pattern, $name)) {
                $matchedIds[] = $id;
            }
        }
    }

    // Response aus den jeweiligen data/<ID>.xml Dateien bauen
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $responseNode = $dom->createElement('response');
    $responseNode->setAttribute('status', 'success');
    $dom->appendChild($responseNode);

    $resultNode = $dom->createElement('result');
    $responseNode->appendChild($resultNode);

    foreach ($matchedIds as $id) {
        $filePath = $dataDir . '/' . $id . '.xml';
        if (file_exists($filePath)) {
            $fileDom = new DOMDocument();
            if (@$fileDom->load($filePath)) {
                $entries = $fileDom->getElementsByTagName('entry');
                if ($entries->length > 0) {
                    foreach ($entries as $entry) {
                        $importedNode = $dom->importNode($entry, true);
                        $resultNode->appendChild($importedNode);
                    }
                } else {
                    $importedNode = $dom->importNode($fileDom->documentElement, true);
                    $resultNode->appendChild($importedNode);
                }
            }
        }
    }

    http_response_code(200);
    echo $dom->saveXML();
    exit();
}

// B) Exakte Einzel-Detail-Abfrage (z.B. <application>chronosphere</application>)
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
$filePath = $dataDir . '/' . $appId . '.xml';

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