<?php
// validate_counts.php

// Pfad zur SQLite-Datenbank festlegen
$dbPath = __DIR__ . '/cloud_appid.db';

if (!file_exists($dbPath)) {
    // Fallback-Pfad im Docker-Container prüfen
    $dbPath = '/var/www/html/cloud_appid.db';
}

if (!file_exists($dbPath)) {
    die("Fehler: Datenbank cloud_appid.db konnte unter $dbPath nicht gefunden werden!\n");
}

try {
    $pdo = new PDO("sqlite:$dbPath");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB-Verbindungsfehler: " . $e->getMessage() . "\n");
}

echo "=====================================================\n";
echo "       CLOUD APP-ID DATENBANK VALIDIERUNG           \n";
echo "=====================================================\n\n";

// A) Gesamtzahl aller Datensätze
$totalRows = (int)$pdo->query("SELECT COUNT(*) FROM cloud_appids")->fetchColumn();

// B) Zähler für die Kategorien
$countCloud       = 0; // >= 1.000.000
$countPredefined  = 0; // < 1.000.000
$countNoId        = 0; // NULL oder Leerstring
$countNonNumeric  = 0; // Enthält Zeichen außer Ziffern

$invalidEntries   = [];

// C) Auswertung aller Einträge
$stmt = $pdo->query("SELECT id, name FROM cloud_appids");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $id = $row['id'];
    $name = $row['name'] ?? 'UNBEKANNT';

    // 1. Prüfen auf NULL oder leeres Feld
    if (is_null($id) || trim((string)$id) === '') {
        $countNoId++;
        $invalidEntries[] = ['type' => 'NULL / LEER', 'id' => 'NULL', 'name' => $name];
        continue;
    }

    $idStr = trim((string)$id);

    // 2. Prüfen, ob die ID ausschließlich aus Ziffern besteht
    if (ctype_digit($idStr)) {
        $idNum = (int)$idStr;
        if ($idNum >= 1000000) {
            $countCloud++;
        } else {
            $countPredefined++;
        }
    } else {
        // 3. Enthält Buchstaben, Sonderzeichen oder negative Werte
        $countNonNumeric++;
        $invalidEntries[] = ['type' => 'KEINE NUMMER', 'id' => $idStr, 'name' => $name];
    }
}

// D) Zusammenfassung ausgeben
echo "GESAMTZAHL Einträge in DB:    " . number_format($totalRows, 0, ',', '.') . "\n";
echo "-----------------------------------------------------\n";
echo "1. Cloud App-IDs (>= 1.000.000): " . number_format($countCloud, 0, ',', '.') . "\n";
echo "2. Predefined App-IDs (< 1.000.000): " . number_format($countPredefined, 0, ',', '.') . "\n";
echo "3. Ohne ID (NULL oder Leer):      " . number_format($countNoId, 0, ',', '.') . "\n";
echo "4. Keine Nummer (Buchstaben/etc): " . number_format($countNonNumeric, 0, ',', '.') . "\n";
echo "-----------------------------------------------------\n";

$totalInvalid = $countNoId + $countNonNumeric;
echo "SUMME ungültige/fehlerhafte IDs: $totalInvalid\n\n";

// E) Details der fehlerhaften IDs ausgeben (falls vorhanden)
if ($totalInvalid > 0) {
    echo "=== FEHLERHAFTE EINTRÄGE (MAX. 20 ANGEZEIGT) ===\n";
    foreach (array_slice($invalidEntries, 0, 20) as $entry) {
        printf(" - [%s] ID: '%s' | Name: %s\n", $entry['type'], $entry['id'], $entry['name']);
    }
    echo "\n";
} else {
    echo "[OK] Alle IDs in der Datenbank sind valide Zahlen.\n\n";
}