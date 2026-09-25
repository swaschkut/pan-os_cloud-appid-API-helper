<?php

$inputFile = 'cloud-appid.txt';

if (!file_exists($inputFile)) {
    die("Fehler: Die Datei '$inputFile' wurde nicht gefunden.\n");
}

// Datei zeilenweise einlesen
$lines = file($inputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$names = [];

foreach ($lines as $line) {
    $line = trim($line);

    // Header und Trennlinie überspringen
    if (strpos($line, 'Id') === 0 || strpos($line, '---') === 0) {
        continue;
    }

    // Zeile anhand von mehreren Leerzeichen/Tabs trennen
    $columns = preg_split('/\s{2,}/', $line);

    // Spalte 2 enthält den Namen
    if (isset($columns[1])) {
        $names[] = $columns[1];
    }
}

// Array ausgeben
print_r($names);