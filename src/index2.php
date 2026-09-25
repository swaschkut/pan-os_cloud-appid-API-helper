<?php
header('Content-Type: application/xml; charset=utf-8');

// 1. Parameter auslesen (GET oder POST)
$cmd = $_GET['cmd'] ?? $_POST['cmd'] ?? '';

if (empty($cmd)) {
    http_response_code(400);
    echo '<response status="error" code="400"><msg><line>Missing cmd parameter</line></msg></response>';
    exit();
}

// 2. Applikationsnamen aus dem XML-Command extrahieren
$appName = '';
if (preg_match('/<application>(.*?)<\/application>/i', $cmd, $matches)) {
    $appName = trim($matches[1]);
}

if (empty($appName)) {
    http_response_code(400);
    echo '<response status="error" code="400"><msg><line>Could not parse application name from command</line></msg></response>';
    exit();
}

// 3. Name -> ID Mapping aus der cloud-appid.txt erstellen
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

    // Header und Trennlinien überspringen
    if (strpos($line, 'Id') === 0 || strpos($line, '---') === 0) {
        continue;
    }

    // Spalten trennen
    $columns = preg_split('/\s{2,}/', $line);

    // Spalte 0 = ID, Spalte 1 = Name
    if (isset($columns[0]) && isset($columns[1])) {
        $id   = trim($columns[0]);
        $name = trim($columns[1]);
        $nameToIdMap[$name] = $id;
    }
}

// 4. Prüfen, ob der Name in der TXT-Datei existiert
if (!isset($nameToIdMap[$appName])) {
    http_response_code(404);
    echo '<response status="error" code="404"><msg><line>Application "' . htmlspecialchars($appName) . '" not found in index</line></msg></response>';
    exit();
}

// 5. Passende ID-Datei im data/ Ordner suchen
$appId = $nameToIdMap[$appName];
$filePath = __DIR__ . '/../data/' . $appId . '.xml';

if (file_exists($filePath)) {
    http_response_code(200);
    echo file_get_contents($filePath);
} else {
    http_response_code(404);
    echo '<response status="error" code="404"><msg><line>XML file for ID ' . htmlspecialchars($appId) . ' (' . htmlspecialchars($appName) . ') missing</line></msg></response>';
}