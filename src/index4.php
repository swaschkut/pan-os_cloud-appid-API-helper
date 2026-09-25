<?php
header('Content-Type: application/xml; charset=utf-8');

// 1. Get command parameter
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

// 2. Check if the query asks for ALL applications
$isAllQuery = preg_match('/<application>\s*<all\s*\/?>/i', $cmd);

if ($isAllQuery) {
    // Read cloud-appid.txt line by line
    $lines = file($txtFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    // Build the XML response directly from the TXT table
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response status="success"/>');
    $result = $xml->addChild('result');

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip header lines
        if (strpos($line, 'Id') === 0 || strpos($line, '---') === 0) {
            continue;
        }

        // Split columns by 2 or more spaces
        $columns = preg_split('/\s{2,}/', $line);

        if (count($columns) >= 5) {
            $id            = $columns[0];
            $name          = $columns[1];
            $receivingTime = $columns[2];
            $taskId        = $columns[3];
            $xmlHashcode   = $columns[4];

            // Add entry element
            $entry = $result->addChild('entry');
            $entry->addAttribute('name', $name);
            $entry->addChild('id', $id);
            $entry->addChild('receiving-time', $receivingTime);
            $entry->addChild('task-id', $taskId);
            $entry->addChild('xml-hashcode', $xmlHashcode);
        }
    }

    http_response_code(200);
    echo $xml->asXML();
    exit();
}

// 3. Single Application Query (uses cloud-appid.txt to map Name -> ID.xml)
$appName = '';
if (preg_match('/<application>(.*?)<\/application>/i', $cmd, $matches)) {
    $appName = trim($matches[1]);
}

if (empty($appName)) {
    http_response_code(400);
    echo '<?xml version="1.0" encoding="UTF-8"?><response status="error" code="400"><msg><line>Could not parse application name</line></msg></response>';
    exit();
}

// Parse TXT to find the ID for the requested application name
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
    http_response_code(200);
    echo file_get_contents($filePath);
} else {
    http_response_code(404);
    echo '<?xml version="1.0" encoding="UTF-8"?><response status="error" code="404"><msg><line>XML file for ID ' . htmlspecialchars($appId) . ' missing</line></msg></response>';
}