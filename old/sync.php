<?php
$dbFile = __DIR__ . '/cloud_appid.db';
$txtFile = __DIR__ . '/cloud-appid.txt';

$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Vorbereiteter Check: Existiert ID und hat sich der Hashcode verändert?
$stmtCheck = $db->prepare("SELECT xml_hashcode FROM cloud_appids WHERE id = :id");

// Vorbereiteter Insert (nutzt dieselbe Parser-Logik wie oben)
// (Verwendet ein REPLACE INTO oder ON CONFLICT UPDATE)

$lines = file($txtFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$skipped = 0;
$downloaded = 0;

foreach ($lines as $line) {
    $line = trim($line);
    if (strpos($line, 'Id') === 0 || strpos($line, '---') === 0) continue;
    $columns = preg_split('/\s{2,}/', $line);
    if (count($columns) < 5) continue;

    $id          = (int)trim($columns[0]);
    $appName     = trim($columns[1]);
    $time        = trim($columns[2]);
    $taskId      = (int)trim($columns[3]);
    $xmlHashcode = trim($columns[4]);

    // Prüfung gegen DB
    $stmtCheck->execute([':id' => $id]);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    // Wenn Eintrag da ist und Hash identisch ist -> Überspringen!
    if ($existing && $existing['xml_hashcode'] === $xmlHashcode) {
        $skipped++;
        continue;
    }

    // ---------------------------------------------------------------------
    // API-Download durchführen für fehlende / geänderte IDs
    // ---------------------------------------------------------------------
    echo "Lade ID $id ($appName) von der Firewall herunter...\n";
    
    // API Call Beispiel
    $apiArgs = ['type' => 'op', 'cmd' => '<show><cloud-appid><application>' . $appName . '</application></cloud-appid></show>'];
    $response = $pan->connector->sendRequest($apiArgs);
    $rawXml = $response->saveXML($response->documentElement);

    // Hier ruft man die Identische Parse- & Insert-Logik aus Skript 2 auf...
    // Insert/Update in DB mit allen Feldern + $rawXml
    
    $downloaded++;
}

echo "Sync abgeschlossen: $downloaded neu heruntergeladen, $skipped übersprungen.\n";