<?php
/**
 * Differential Sync for Cloud-AppID and Predefined Objects Sync using pan-os-php Framework
 */

set_include_path(dirname(__FILE__) . '/utils/' . PATH_SEPARATOR . get_include_path());
require_once dirname(__FILE__) . "/../pan-os-php/lib/pan_php_framework.php";
require_once dirname(__FILE__) . "/../pan-os-php/utils/lib/UTIL.php";

PH::print_stdout();
PH::print_stdout("***********************************************");
PH::print_stdout("*********** " . basename(__FILE__) . " UTILITY **************");
PH::print_stdout();

PH::print_stdout("PAN-OS-PHP version: " . PH::frameworkVersion());

$supportedArguments = Array();
$supportedArguments['in'] = Array('niceName' => 'in', 'shortHelp' => 'api target. ie: in=api://192.168.1.1 or in=api://serial@IP', 'argDesc' => '[api://IP]|[api://serial@IP]');
$supportedArguments['debugapi'] = Array('niceName' => 'DebugAPI', 'shortHelp' => 'prints API calls when they happen');
$supportedArguments['help'] = Array('niceName' => 'help', 'shortHelp' => 'this message');
$supportedArguments['folder'] = Array('niceName' => 'folder', 'shortHelp' => 'specify the folder where offline files should be saved');
$supportedArguments['force'] = Array('niceName' => 'force', 'shortHelp' => 'force redownload even if file already exists');
$supportedArguments['newfile'] = Array('niceName' => 'newfile', 'shortHelp' => 'path to the new cloud-appid index file (default: cloud-appid_new.txt)');

$usageMsg = PH::boldText("USAGE: ") . "php " . basename(__FILE__) . " in=api://192.168.1.1 folder=data\n";

$util = new UTIL("custom", $argv, $argc, __FILE__, $supportedArguments, $usageMsg);
$util->utilInit();

$pan = $util->pan;
$connector = $pan->connector;

if ($util->configInput['type'] != 'api') {
    derr("This script works ONLY in API mode (e.g. in=api://192.168.1.1)\n");
}

$oldTxtFile    = 'cloud-appid.txt';
$newTxtFile    = isset($util->arguments['newfile']) ? $util->arguments['newfile'] : 'cloud-appid_new.txt';
$outputFolder  = isset($util->arguments['folder']) ? $util->arguments['folder'] : 'data';
$forceDownload = isset($util->arguments['force']);

// Pfade für predefined.xml (Quelle vs. Ziel)
$sourcePredefinedXml = dirname(__FILE__) . "/../pan-os-php/lib/object-classes/predefined.xml";
$targetPredefinedXml = $outputFolder . "/predefined.xml";

if (!is_dir($outputFolder)) {
    if (!mkdir($outputFolder, 0777, true)) {
        derr("Fehler: Ordner '$outputFolder' konnte nicht erstellt werden.\n");
    }
}

// -------------------------------------------------------------------------
// Helper: Interactive SSH Password Prompt
// -------------------------------------------------------------------------
function promptHiddenPassword($prompt = "Geben Sie das SSH-Passwort ein: ") {
    echo $prompt;
    system('stty -echo');
    $password = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";
    return $password;
}

// -------------------------------------------------------------------------
// Helper: Fetch SSH output directly via expect (raw output, no filtering)
// -------------------------------------------------------------------------
function fetchCloudAppIdViaExpect($host, $user, $password, $outputFile) {
    PH::print_stdout("Starte SSH-Verbindung via system expect zu $host...");

    $escapedPassword = addcslashes($password, '"$\\`[]');

    $expectScript = <<<EXPECT
set timeout 600
match_max 1048576

spawn ssh -o StrictHostKeyChecking=no {$user}@{$host}
expect {
    "password:" { send "{$escapedPassword}\r" }
    "Password:" { send "{$escapedPassword}\r" }
    timeout { exit 1 }
}
expect ">"
send "set cli pager off\r"
expect ">"
send "set cli terminal length 0\r"
expect ">"
send "show cloud-appid cloud-app-data application all\r"
expect ">"
send "exit\r"
expect eof
EXPECT;

    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];

    $process = proc_open("expect", $descriptorspec, $pipes);

    if (!is_resource($process)) {
        PH::print_stdout(" -> Fehler: Expect-Prozess konnte nicht gestartet werden.");
        return false;
    }

    fwrite($pipes[0], $expectScript);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    proc_close($process);

    if (empty($stdout)) {
        PH::print_stdout(" -> SSH/Expect Fehler: Keine Ausgabe empfangen. " . trim($stderr));
        return false;
    }

    if (file_put_contents($outputFile, $stdout) !== false) {
        PH::print_stdout(" -> Erfolgreich! $outputFile wurde über SSH aktualisiert.");
        return true;
    }

    return false;
}

// -------------------------------------------------------------------------
// Helper: Check and Sync predefined.xml via File Copy
// -------------------------------------------------------------------------
function checkAndCopyPredefinedXml($sourcePath, $targetPath, $force = false) {
    PH::print_stdout("Prüfe 'predefined.xml' über Datei-Kopie...");

    if (!file_exists($sourcePath)) {
        PH::print_stdout(" -> WARNUNG: Quell-Datei '$sourcePath' existiert nicht. Kopieren übersprungen.");
        return;
    }

    $sourceHash = md5_file($sourcePath);
    $targetHash = file_exists($targetPath) ? md5_file($targetPath) : null;

    if (!$force && $targetHash !== null && $sourceHash === $targetHash) {
        PH::print_stdout(" -> 'predefined.xml' ist am Zielort bereits aktuell (MD5: $sourceHash).");
        return;
    }

    PH::print_stdout(" -> Kopiere '$sourcePath' nach '$targetPath'...");

    if (copy($sourcePath, $targetPath)) {
        PH::print_stdout(" -> SUCCESS: 'predefined.xml' erfolgreich kopiert! (MD5: $sourceHash)");
    } else {
        PH::print_stdout(" -> FEHLER: Kopieren von 'predefined.xml' fehlgeschlagen.");
    }
}

// -------------------------------------------------------------------------
// 1. Predefined XML Copy Check
// -------------------------------------------------------------------------
checkAndCopyPredefinedXml($sourcePredefinedXml, $targetPredefinedXml, $forceDownload);

// -------------------------------------------------------------------------
// 2. Cloud App Version & Timestamp Validation
// -------------------------------------------------------------------------
function parseVersionAndTimestamp($xmlContent) {
    if (empty($xmlContent)) return null;

    $xml = @simplexml_load_string($xmlContent);
    if ($xml === false || !isset($xml->result)) return null;

    $resultText = (string)$xml->result;
    $version   = null;
    $timestamp = null;

    if (preg_match('/Cloud App Version:\s*(\S+)/i', $resultText, $matches)) {
        $version = $matches[1];
    }
    if (preg_match('/Cloud App Timestamp:\s*(\S+)/i', $resultText, $matches)) {
        $timestamp = $matches[1];
    }

    if ($version !== null || $timestamp !== null) {
        return ['version' => $version, 'timestamp' => $timestamp];
    }

    return null;
}

PH::print_stdout();
PH::print_stdout("Prüfe Cloud App Version und Timestamp...");

$versionFile = 'cloud-appid-version.xml';
$previousVersionData = null;

if (file_exists($versionFile)) {
    $previousXml = file_get_contents($versionFile);
    $previousVersionData = parseVersionAndTimestamp($previousXml);
}

$apiArgsVersion = [
    'type' => 'op',
    'cmd'  => '<show><cloud-appid><version></version></cloud-appid></show>'
];

try {
    $response = $pan->connector->sendRequest($apiArgsVersion);
    $currentXmlString = $response->saveXML($response->documentElement);

    $currentVersionData = parseVersionAndTimestamp($currentXmlString);

    if ($previousVersionData && $currentVersionData) {
        $versionDiff   = ($previousVersionData['version'] !== $currentVersionData['version']);
        $timestampDiff = ($previousVersionData['timestamp'] !== $currentVersionData['timestamp']);

        if ($versionDiff || $timestampDiff) {
            PH::print_stdout();
            PH::print_stdout("*******************************************************************");
            PH::print_stdout("WARNUNG: Cloud App Version / Timestamp geändert!");
            PH::print_stdout("  Vorherige Version : " . ($previousVersionData['version'] ?? 'N/A') . " | Timestamp: " . ($previousVersionData['timestamp'] ?? 'N/A'));
            PH::print_stdout("  Aktuelle Version  : " . ($currentVersionData['version'] ?? 'N/A') . " | Timestamp: " . ($currentVersionData['timestamp'] ?? 'N/A'));
            PH::print_stdout("*******************************************************************");
            PH::print_stdout();

            // Interaktive Passwortabfrage
            $targetHost = $pan->connector->apihost;
            $sshUser    = "admin";

            $sshPass = promptHiddenPassword("Bitte SSH-Passwort für '$sshUser@$targetHost' eingeben: ");

            $sshSuccess = fetchCloudAppIdViaExpect($targetHost, $sshUser, $sshPass, $newTxtFile);

            if (!$sshSuccess) {
                PH::print_stdout(" -> SSH-Update fehlgeschlagen. Abbruch.");
                exit(1);
            }
        }
        else
        {
            PH::print_stdout();
            PH::print_stdout("*******************************************************************");
            PH::print_stdout("Keine Abweichung bei Cloud App Version oder Timestamp festgestellt!");
            PH::print_stdout("*******************************************************************");
            PH::print_stdout();
            exit();
        }
    }

    file_put_contents($versionFile, $currentXmlString);

} catch (Exception $e) {
    PH::print_stdout(" -> Fehler beim Abrufen der Cloud App Version: " . $e->getMessage());
}

if (!file_exists($newTxtFile)) {
    derr("Fehler: Neue Index-Datei '$newTxtFile' nicht gefunden.\n");
}

// -------------------------------------------------------------------------
// Robust Parsing Function for Cloud App Index
// -------------------------------------------------------------------------
function parseCloudAppIndex($filePath) {
    $indexMap = [];
    if (!file_exists($filePath)) return $indexMap;

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (
            strpos($trimmed, 'show cloud-appid') !== false ||
            strpos($trimmed, 'set cli') !== false ||
            strpos($trimmed, 'spawn ssh') !== false ||
            strpos($trimmed, 'Last login:') !== false ||
            strpos($trimmed, 'Id ') === 0 ||
            strpos($trimmed, '---') === 0 ||
            strpos($trimmed, 'total ') === 0 ||
            strpos($trimmed, 'Current Time:') === 0 ||
            strpos($trimmed, 'Connection to ') === 0 ||
            preg_match('/^[\w\.\-]+@[\w\.\-]+.*>/', $trimmed)
        ) {
            continue;
        }

        if (!preg_match('/^\s*(\d+)\s+(.+)$/', $line, $matches)) {
            continue;
        }

        $id        = (int)$matches[1];
        $restOfLine = trim($matches[2]);

        $columns = preg_split('/\s{2,}/', $restOfLine);

        if (count($columns) >= 4) {
            $indexMap[$id] = [
                'name'         => trim($columns[0]),
                'time'         => trim($columns[1]),
                'task_id'      => trim($columns[2]),
                'xml_hashcode' => trim($columns[3])
            ];
        } else {
            $parts = preg_split('/\s+/', $restOfLine);
            if (count($parts) >= 4) {
                $hash   = array_pop($parts);
                $taskId = array_pop($parts);
                $time   = array_pop($parts) . ' ' . array_pop($parts);
                $name   = implode(' ', $parts);

                $indexMap[$id] = [
                    'name'         => $name,
                    'time'         => $time,
                    'task_id'      => $taskId,
                    'xml_hashcode' => $hash
                ];
            }
        }
    }

    return $indexMap;
}

PH::print_stdout("Lese vorhandene Index-Dateien ein...");
$oldIndex = parseCloudAppIndex($oldTxtFile);
$newIndex = parseCloudAppIndex($newTxtFile);

// -------------------------------------------------------------------------
// Differenzierte Analyse
// -------------------------------------------------------------------------
$toDownload   = [];
$countNew     = 0;
$countChanged = 0;
$countMissing = 0;

foreach ($newIndex as $id => $newItem) {
    $filePath = $outputFolder . '/' . $id . '.xml';

    if ($forceDownload) {
        $toDownload[$id] = ['item' => $newItem, 'type' => 'FORCED'];
        $countChanged++;
        continue;
    }

    if (!isset($oldIndex[$id])) {
        $toDownload[$id] = ['item' => $newItem, 'type' => 'NEW'];
        $countNew++;
        continue;
    }

    if (!file_exists($filePath)) {
        $toDownload[$id] = ['item' => $newItem, 'type' => 'MISSING'];
        $countMissing++;
        continue;
    }

    if ($oldIndex[$id]['xml_hashcode'] !== $newItem['xml_hashcode']) {
        $toDownload[$id] = ['item' => $newItem, 'type' => 'CHANGED'];
        $countChanged++;
    }
}

$totalToDownload = count($toDownload);

// -------------------------------------------------------------------------
// Konsolenausgabe
// -------------------------------------------------------------------------
PH::print_stdout();
PH::print_stdout("Analyse beendet:");
PH::print_stdout(" - Gesamt-Einträge in alter Index-Datei: " . count($oldIndex));
PH::print_stdout(" - Gesamt-Einträge in neuer Index-Datei: " . count($newIndex));
PH::print_stdout(" - Herunterzuladen (Gesamt)             : " . $totalToDownload);
PH::print_stdout("   ├─ Neue App-IDs (noch nicht im Index): " . $countNew);
PH::print_stdout("   ├─ Geänderte App-IDs (Hash-Abweichung): " . $countChanged);
PH::print_stdout("   └─ Fehlende Dateien (lokal nicht da) : " . $countMissing);
PH::print_stdout();

if ($totalToDownload === 0) {
    PH::print_stdout("Keine Änderungen vorhanden. Ersetze Index-Datei...");
    rename($newTxtFile, $oldTxtFile);
    PH::print_stdout("************* END OF SCRIPT " . basename(__FILE__) . " ************");
    exit(0);
}

// -------------------------------------------------------------------------
// Download-Schleife via API
// -------------------------------------------------------------------------
$successCount = 0;

foreach ($toDownload as $id => $entry) {
    $item     = $entry['item'];
    $type     = $entry['type'];
    $name     = $item['name'];
    $filePath = $outputFolder . '/' . $id . '.xml';

    PH::print_stdout("[$type] Lade App-ID $id ($name) herunter...");

    $apiArgs = Array();
    $apiArgs['type'] = 'op';
    $apiArgs['cmd'] = '<show><cloud-appid><application>' . $name . '</application></cloud-appid></show>';

    try {
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

if ($successCount === $totalToDownload) {
    PH::print_stdout();
    PH::print_stdout("Alle $successCount Dateien erfolgreich heruntergeladen. Aktualisiere $oldTxtFile...");
    rename($newTxtFile, $oldTxtFile);
} else {
    PH::print_stdout();
    PH::print_stdout("WARNUNG: Es gab Fehler. '$newTxtFile' bleibt für erneute Versuche bestehen.");
}

PH::print_stdout();
PH::print_stdout("************* END OF SCRIPT " . basename(__FILE__) . " ************");
PH::print_stdout();