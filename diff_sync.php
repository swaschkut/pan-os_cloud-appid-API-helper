<?php
/**
 * Differential Sync for Cloud-AppID using pan-os-php Framework
 */

set_include_path(dirname(__FILE__) . '/utils/' . PATH_SEPARATOR . get_include_path());
require_once dirname(__FILE__) . "/../pan-os-php/lib/pan_php_framework.php";
require_once dirname(__FILE__) . "/../pan-os-php/utils/lib/UTIL.php";

PH::print_stdout();
PH::print_stdout("***********************************************");
PH::print_stdout("*********** " . basename(__FILE__) . " UTILITY **************");
PH::print_stdout();

PH::print_stdout("PAN-OS-PHP version: " . PH::frameworkVersion());

// 1. Argumente nach Vorbild deines Referenzskripts definieren
$supportedArguments = Array();
$supportedArguments['in'] = Array('niceName' => 'in', 'shortHelp' => 'api target. ie: in=api://192.168.1.1 or in=api://serial@IP', 'argDesc' => '[api://IP]|[api://serial@IP]');
$supportedArguments['debugapi'] = Array('niceName' => 'DebugAPI', 'shortHelp' => 'prints API calls when they happen');
$supportedArguments['help'] = Array('niceName' => 'help', 'shortHelp' => 'this message');
$supportedArguments['folder'] = Array('niceName' => 'folder', 'shortHelp' => 'specify the folder where offline files should be saved');
$supportedArguments['force'] = Array('niceName' => 'force', 'shortHelp' => 'force redownload even if file already exists');
$supportedArguments['newfile'] = Array('niceName' => 'newfile', 'shortHelp' => 'path to the new cloud-appid index file (default: cloud-appid_neu.txt)');

$usageMsg = PH::boldText("USAGE: ") . "php " . basename(__FILE__) . " in=api://192.168.1.1 folder=data\n";

$util = new UTIL("custom", $argv, $argc, __FILE__, $supportedArguments, $usageMsg);
$util->utilInit();

$pan = $util->pan;
$connector = $pan->connector;

if ($util->configInput['type'] != 'api') {
    derr("This script works ONLY in API mode (e.g. in=api://192.168.1.1)\n");
}

// 2. Pfade & Optionen vorbereiten
$oldTxtFile  = 'cloud-appid.txt';
$newTxtFile  = isset($util->arguments['newfile']) ? $util->arguments['newfile'] : 'cloud-appid_neu.txt';
$outputFolder = isset($util->arguments['folder']) ? $util->arguments['folder'] : 'data';
$forceDownload = isset($util->arguments['force']);

if (!is_dir($outputFolder)) {
    if (!mkdir($outputFolder, 0777, true)) {
        derr("Fehler: Ordner '$outputFolder' konnte nicht erstellt werden.\n");
    }
}

if (!file_exists($newTxtFile)) {
    derr("Fehler: Neue Index-Datei '$newTxtFile' nicht gefunden.\n");
}

// 3. Parser-Funktion für cloud-appid Index-Dateien
function parseCloudAppIndex($filePath) {
    $indexMap = [];
    if (!file_exists($filePath)) return $indexMap;

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, 'Id') === 0 || strpos($line, '---') === 0) continue;

        $columns = preg_split('/\s{2,}/', $line);
        if (count($columns) >= 5) {
            $id = (int)trim($columns[0]);
            $indexMap[$id] = [
                'name'         => trim($columns[1]),
                'time'         => trim($columns[2]),
                'task_id'      => trim($columns[3]),
                'xml_hashcode' => trim($columns[4])
            ];
        }
    }
    return $indexMap;
}

PH::print_stdout("Lese vorhandene Index-Dateien ein...");
$oldIndex = parseCloudAppIndex($oldTxtFile);
$newIndex = parseCloudAppIndex($newTxtFile);

// 4. Differential-Analyse (Diff-Logik)
$toDownload = [];

foreach ($newIndex as $id => $newItem) {
    $filePath = $outputFolder . '/' . $id . '.xml';

    if ($forceDownload) {
        $toDownload[$id] = $newItem;
        continue;
    }

    // Neu/Fehlt lokal?
    if (!isset($oldIndex[$id]) || !file_exists($filePath)) {
        $toDownload[$id] = $newItem;
        continue;
    }

    // Hashcode abweichend?
    if ($oldIndex[$id]['xml_hashcode'] !== $newItem['xml_hashcode']) {
        $toDownload[$id] = $newItem;
    }
}

PH::print_stdout("Analyse beendet:");
PH::print_stdout(" - Gesamt in neuer Index-Datei: " . count($newIndex));
PH::print_stdout(" - Herunterzuladen (Neu/Geändert/Fehlend): " . count($toDownload));

if (count($toDownload) === 0) {
    PH::print_stdout("Keine Änderungen vorhanden. Ersetze Index-Datei...");
    rename($newTxtFile, $oldTxtFile);
    PH::print_stdout("************* END OF SCRIPT " . basename(__FILE__) . " ************");
    exit(0);
}

// 5. Download-Schleife über Connector
$successCount = 0;

foreach ($toDownload as $id => $item) {
    $name = $item['name'];
    $filePath = $outputFolder . '/' . $id . '.xml';

    PH::print_stdout("Lade App-ID $id ($name) herunter...");

    $apiArgs = Array();
    $apiArgs['type'] = 'op';
    $apiArgs['cmd'] = '<show><cloud-appid><application>' . $name . '</application></cloud-appid></show>';

    try {
        // Aufruf über das pan-os-php Framework
        $response = $pan->connector->sendRequest($apiArgs);
        $xmlString = $response->saveXML($response->documentElement);

        if (file_put_contents($filePath, $xmlString) !== false) {
            PH::print_stdout(" -> Erfolgreich gespeichert: " . $filePath);
            $successCount++;
        } else {
            PH::print_stdout(" -> Fehler beim Schreiben der Datei: " . $filePath);
        }
    } catch (Exception $e) {
        PH::print_stdout(" -> API-Fehler bei $name: " . $e->getMessage());
    }
}

// 6. Index nach erfolgreichem Durchlauf ersetzen
if ($successCount === count($toDownload)) {
    PH::print_stdout("Alle $successCount geänderten Dateien heruntergeladen. Aktualisiere $oldTxtFile...");
    rename($newTxtFile, $oldTxtFile);
} else {
    PH::print_stdout("WARNUNG: Es gab Fehler. '$newTxtFile' bleibt für erneute Versuche bestehen.");
}

PH::print_stdout();
PH::print_stdout("************* END OF SCRIPT " . basename(__FILE__) . " ************");
PH::print_stdout();