<?php
// validate_predefined.php

$predefinedXml = __DIR__ . '/predefined.xml';
$txtFile       = __DIR__ . '/cloud-appid.txt';

if (!file_exists($predefinedXml)) {
    die("Fehler: predefined.xml wurde unter $predefinedXml nicht gefunden!\n");
}

echo "=====================================================\n";
echo "      VALIDIERUNG VON PREDEFINED.XML                \n";
echo "=====================================================\n\n";

// 1. cloud-appid.txt einlesen
$metaData = [];
if (file_exists($txtFile)) {
    $handle = fopen($txtFile, 'r');
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line) || strpos($line, 'Id') === 0 || strpos($line, '---') === 0) continue;
            $cols = preg_split('/\s{2,}/', $line);
            if (count($cols) >= 5) {
                $metaData[trim($cols[1])] = (int)trim($cols[0]);
            }
        }
        fclose($handle);
    }
}
echo "Metadaten aus cloud-appid.txt geladen: " . count($metaData) . " Einträge.\n";

// 2. XML einlesen und Parser-Fehler abfangen
libxml_use_internal_errors(true);
$rawXml = file_get_contents($predefinedXml);
$xml = simplexml_load_string($rawXml);

if ($xml === false) {
    echo "\n[FEHLER] XML ist ungültig! LibXML Fehler:\n";
    foreach (libxml_get_errors() as $error) {
        echo " - Line {$error->line}: {$error->message}\n";
    }
    libxml_clear_errors();
    exit;
}

// 3. XPath-Knoten prüfen
$entries = $xml->xpath('/predefined/application/entry') ?: $xml->xpath('//application/entry') ?: $xml->xpath('//entry');

echo "Gefundene <entry>-Knoten im XML: " . count($entries) . "\n\n";

// 4. Analyse der einzelnen Einträge
$seenIds = [];
$duplicates = [];
$noIdCount = 0;
$noNameCount = 0;
$validPredefined = 0;

foreach ($entries as $index => $entry) {
    $name = (string)($entry['name'] ?? '');

    if (empty($name)) {
        $noNameCount++;
        continue;
    }

    // ID-Ermittlung analog zu deinem Import-Script
    $id = null;
    if (isset($entry['id']) && (string)$entry['id'] !== '') {
        $id = (int)$entry['id'];
    } elseif (isset($metaData[$name])) {
        $id = $metaData[$name];
    }

    if ($id === null) {
        $noIdCount++;
        echo " - WARNUNG: Kein ID-Match für App-Name '$name' (Knoten #$index)\n";
        continue;
    }

    // Duplikate-Check
    if (isset($seenIds[$id])) {
        $duplicates[] = [
            'id' => $id,
            'firstName' => $seenIds[$id],
            'secondName' => $name
        ];
    } else {
        $seenIds[$id] = $name;
        if ($id < 1000000) {
            $validPredefined++;
        }
    }
}

// 5. Zusammenfassung ausgeben
echo "-----------------------------------------------------\n";
echo "RESULTATE DER PREDEFINED.XML ANALYSE:\n";
echo "-----------------------------------------------------\n";
echo "Gefundene Applikationen gesamt:       " . count($entries) . "\n";
echo "Valide Predefined IDs (< 1.000.000):  " . $validPredefined . "\n";
echo "Einträge ohne Name (übersprungen):    " . $noNameCount . "\n";
echo "Einträge ohne verknüpfbare ID:        " . $noIdCount . "\n";
echo "Doppelte IDs im XML/Meta-Overlap:     " . count($duplicates) . "\n";
echo "-----------------------------------------------------\n";

if (count($duplicates) > 0) {
    echo "\n=== ERSTE 10 DOPPELTE IDS (WERDEN DURCH 'INSERT OR REPLACE' ÜBERSCHRIEBEN) ===\n";
    foreach (array_slice($duplicates, 0, 10) as $dup) {
        echo " - ID {$dup['id']}: '{$dup['firstName']}' wird ersetzt durch '{$dup['secondName']}'\n";
    }
}