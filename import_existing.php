<?php
ini_set('memory_limit', '-1');

$dbPath = __DIR__ . '/cloud_appid.db';

// 1. Datenbankverbindung herstellen
$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 2. Tabelle 'appids' automatisch erstellen, falls sie fehlt
$pdo->exec("
    CREATE TABLE IF NOT EXISTS appids (
        appid TEXT PRIMARY KEY,
        data TEXT,
        updated_at TEXT
    )
");

// 3. XML-Dateien einlesen
$files = glob(__DIR__ . '/*.xml'); // Passe das Verzeichnis ggf. an, z.B. __DIR__ . '/data/*.xml'
echo "Gefundene XML-Dateien: " . count($files) . "\n";

if (empty($files)) {
    echo "Keine XML-Dateien zum Importieren gefunden.\n";
    exit(0);
}

// 4. Daten einfügen
$stmt = $pdo->prepare("INSERT OR REPLACE INTO appids (appid, data, updated_at) VALUES (:appid, :data, :updated_at)");

$pdo->beginTransaction();

foreach ($files as $file) {
    $filename = basename($file, '.xml');
    $content = file_get_contents($file);

    $stmt->execute([
        ':appid'      => $filename,
        ':data'       => $content,
        ':updated_at' => date('Y-m-d H:i:s')
    ]);
}

$pdo->commit();
echo "Import erfolgreich abgeschlossen.\n";