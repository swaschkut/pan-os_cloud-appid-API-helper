<?php
header('Content-Type: application/xml; charset=utf-8');

// Path & Parameters
$cmd = $_GET['cmd'] ?? $_POST['cmd'] ?? '';

// Prüfen, ob eine Anfrage im PAN-OS Format vorliegt
if (empty($cmd)) {
    http_response_code(400);
    echo '<response status="error" code="400"><msg><line>Missing cmd parameter</line></msg></response>';
    exit();
}

// Applikationsnamen aus dem Query-XML extrahieren
// Beispiel Input: <show><cloud-appid><application>chronosphere</application></cloud-appid></show>
$appName = '';
if (preg_match('/<application>(.*?)<\/application>/i', $cmd, $matches)) {
    $appName = trim($matches[1]);
}

if (empty($appName)) {
    http_response_code(400);
    echo '<response status="error" code="400"><msg><line>Could not parse application name</line></msg></response>';
    exit();
}

// Pfad zur XML-Datei bestimmen (Beispiel: data/chronosphere.xml oder über Mapping)
$filePath = __DIR__ . '/../data/' . $appName . '.xml';

if (file_exists($filePath)) {
    http_response_code(200);
    echo file_get_contents($filePath);
} else {
    // Standard PAN-OS API Fehler-Antwort nachbilden
    http_response_code(404);
    echo '<response status="error" code="404"><msg><line>Application ' . htmlspecialchars($appName) . ' not found</line></msg></response>';
}