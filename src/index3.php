<?php
header('Content-Type: application/xml; charset=utf-8');

// 1. Parameter auslesen (GET oder POST)
$cmd = $_GET['cmd'] ?? $_POST['cmd'] ?? '';

if (empty($cmd)) {
    http_response_code(400);
    echo '<response status="error" code="400"><msg><line>Missing cmd parameter</line></msg></response>';
    exit();
}

// 2. Prüfen, ob die Anfrage "ALL" (alle Applikationen) anfordert
$isAllQuery = preg_match('/<application>\s*<all\s*\/?>\s*<\/all>\s*<\/application>/i', $cmd)
    || preg_match('/<application>\s*<all\s*\/>\s*<\/application>/i', $cmd);

if ($isAllQuery) {
    // Ordner mit den einzelnen XML-Dateien
    $dataDir = __DIR__ . '/../data';

    if (!is_dir($dataDir)) {
        http_response_code(500);
        echo '<response status="error" code="500"><msg><line>Data directory not found</line></msg></response>';
        exit();
    }

    // Neues DOMDocument für die Gesamtantwort erstellen
    $combinedDom = new DOMDocument('1.0', 'UTF-8');
    $responseNode = $combinedDom->createElement('response');
    $responseNode->setAttribute('status', 'success');
    $combinedDom->appendChild($responseNode);

    $resultNode = $combinedDom->createElement('result');
    $responseNode->appendChild($resultNode);

    // Alle XML-Dateien im data/-Ordner durchlaufen
    $files = glob($dataDir . '/*.xml');

    foreach ($files as $file) {
        $entryDom = new DOMDocument();
        // Unterdrücke Warnungen bei eventuell fehlerhaftem XML
        if (@$entryDom->load($file)) {
            $root = $entryDom->documentElement;

            // Falls die gespeicherte Datei bereits ein <entry>-Element ist
            if ($root->nodeName === 'entry') {
                $importedNode = $combinedDom->importNode($root, true);
                $resultNode->appendChild($importedNode);
            } else {
                // Falls die gespeicherte Datei die vollständige Response enthält (<response><result><entry>...</entry></result></response>)
                $entries = $entryDom->getElementsByTagName('entry');
                foreach ($entries as $entry) {
                    $importedNode = $combinedDom->importNode($entry, true);
                    $resultNode->appendChild($importedNode);
                }
            }
        }
    }

    // Gesamtes XML ausgeben
    echo $combinedDom->saveXML();
    exit();
}

// 3. Einzelabfrage (Standard-Logik über cloud-appid.txt)
$appName = '';
if (preg_match('/<application>(.*?)<\/application>/i', $cmd, $matches)) {
    $appName = trim($matches[1]);
}

if (empty($appName)) {
    http_response_code(400);
    echo '<response status="error" code="400"><msg><line>Could not parse application name from command</line></msg></response>';
    exit();
}

// Mapping über cloud-appid.txt
$txtFile = __DIR__ . '/../cloud-appid.txt';

if (!file_exists($txtFile)) {
    http_response_code(500);
    echo '<response status="error" code="500"><msg><line>Index file cloud-appid.txt not found</line></msg></response>';
    exit();
}

$nameToIdMap = [];
$lines = file($txtFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $line) {
    $line = trim($line);
    if (strpos($line, 'Id') === 0 || strpos($line, '---') === 0) {
        continue;
    }
    $columns = preg_split('/\s{2,}/', $line);
    if (isset($columns[0]) && isset($columns[1])) {
        $nameToIdMap[trim($columns[1])] = trim($columns[0]);
    }
}

if (!isset($nameToIdMap[$appName])) {
    http_response_code(404);
    echo '<response status="error" code="404"><msg><line>Application "' . htmlspecialchars($appName) . '" not found in index</line></msg></response>';
    exit();
}

$appId = $nameToIdMap[$appName];
$filePath = __DIR__ . '/../data/' . $appId . '.xml';

if (file_exists($filePath)) {
    http_response_code(200);
    echo file_get_contents($filePath);
} else {
    http_response_code(404);
    echo '<response status="error" code="404"><msg><line>XML file for ID ' . htmlspecialchars($appId) . ' missing</line></msg></response>';
}