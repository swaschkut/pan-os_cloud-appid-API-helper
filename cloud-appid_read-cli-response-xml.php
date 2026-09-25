<?php

// Konfiguration
$inputFile = 'cloud-appid.txt';
$outputFile = 'panos_response.xml';

// Prüfen, ob die Eingabedatei existiert
if (!file_exists($inputFile)) {
    die("Fehler: Die Datei '$inputFile' wurde nicht gefunden.\n");
}

// Datei zeilenweise einlesen
$lines = file($inputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

// SimpleXMLElement im PAN-OS XML-API Format erstellen
// Struktur: <response status="success"><result><entry>...</entry></result></response>
$xml = new SimpleXMLElement('<response status="success"/>');
$result = $xml->addChild('result');

$isHeaderParsed = false;

foreach ($lines as $line) {
    $line = trim($line);

    // Header und Trennlinie überspringen
    if (strpos($line, 'Id') === 0 || strpos($line, '---') === 0) {
        continue;
    }

    // Zeile anhand von mehreren Leerzeichen/Tabs auftrennen
    $columns = preg_split('/\s{2,}/', $line);

    // Erwartet werden 5 Spalten (Id, Name, Receiving Time, Task Id, XML Hashcode)
    if (count($columns) >= 5) {
        $id            = $columns[0];
        $name          = $columns[1];
        $receivingTime = $columns[2];
        $taskId        = $columns[3];
        $xmlHashcode   = $columns[4];

        // XML Element für den Eintrag erstellen
        $entry = $result->addChild('entry');
        $entry->addAttribute('name', $name);
        $entry->addChild('id', $id);
        $entry->addChild('receiving-time', $receivingTime);
        $entry->addChild('task-id', $taskId);
        $entry->addChild('xml-hashcode', $xmlHashcode);
    }
}

// XML formatiert speichern (mit Einrückung)
$dom = new DOMDocument('1.0', 'UTF-8');
$dom->preserveWhiteSpace = false;
$dom->formatOutput = true;
$dom->loadXML($xml->asXML());

if ($dom->save($outputFile)) {
    echo "Die XML-Datei wurde erfolgreich unter '$outputFile' gespeichert.\n";
} else {
    echo "Fehler beim Speichern der XML-Datei.\n";
}