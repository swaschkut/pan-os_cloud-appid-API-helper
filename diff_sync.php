<?php
/**
 * Differential Sync for Cloud-AppID Objects & Cloud-Container Index Sync using pan-os-php Framework
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
$supportedArguments['newcontainerfile'] = Array('niceName' => 'newcontainerfile', 'shortHelp' => 'path to the new cloud-container index file (default: cloud-container_new.txt)');

$usageMsg = PH::boldText("USAGE: ") . "php " . basename(__FILE__) . " in=api://192.168.1.1 folder=data\n";

$util = new UTIL("custom", $argv, $argc, __FILE__, $supportedArguments, $usageMsg);
$util->utilInit();

$pan = $util->pan;
$connector = $pan->connector;

if ($util->configInput['type'] != 'api') {
    derr("This script works ONLY in API mode (e.g. in=api://192.168.1.1)\n");
}

$oldTxtFile          = 'cloud-appid.txt';
$newTxtFile          = isset($util->arguments['newfile']) ? $util->arguments['newfile'] : 'cloud-appid_new.txt';

$oldContainerTxtFile = 'cloud-container.txt';
$newContainerTxtFile = isset($util->arguments['newcontainerfile']) ? $util->arguments['newcontainerfile'] : 'cloud-container_new.txt';

$outputFolder        = isset($util->arguments['folder']) ? $util->arguments['folder'] : 'data';
$forceDownload       = isset($util->arguments['force']);

// Framework source path for predefined.xml
$sourcePredefinedXml = dirname(__FILE__) . "/../pan-os-php/lib/object-classes/predefined.xml";

if (!is_dir($outputFolder)) {
    if (!mkdir($outputFolder, 0777, true)) {
        derr("Error: Could not create output folder '$outputFolder'.\n");
    }
}

// -------------------------------------------------------------------------
// Helper: Interactive SSH Password Prompt
// -------------------------------------------------------------------------
function promptHiddenPassword($prompt = "Enter SSH Password: ") {
    echo $prompt;
    system('stty -echo');
    $password = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";
    return $password;
}

// -------------------------------------------------------------------------
// Helper: Extract ONLY table rows (strips prompts, command echo, banners, exit, totals)
// -------------------------------------------------------------------------
function sanitizeTableOutput($rawText) {
    $lines = explode("\n", $rawText);
    $cleanLines = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Skip non-table metadata, banners, prompts, invalid syntax errors, and totals
        if (
            empty($trimmed) ||
            strpos($trimmed, 'show cloud-appid') !== false ||
            strpos($trimmed, 'set cli') !== false ||
            strpos($trimmed, 'spawn ssh') !== false ||
            strpos($trimmed, 'Last login:') !== false ||
            strpos($trimmed, 'Current Time:') !== false ||
            strpos($trimmed, 'Connection to ') !== false ||
            strpos($trimmed, 'Invalid syntax') !== false ||
            strpos($trimmed, 'Number of failed attempts') !== false ||
            strpos($trimmed, 'total ') === 0 ||
            strpos($trimmed, 'exit') === 0 ||
            preg_match('/^\(admin@[\w\.\-]+\)\s*Password:/i', $trimmed) ||
            preg_match('/^[\w\.\-]+@[\w\.\-]+.*>/', $trimmed) ||
            preg_match('/^admin@.*>/', $trimmed)
        ) {
            continue;
        }

        $cleanLines[] = $line;
    }

    return implode("\n", $cleanLines) . "\n";
}

// -------------------------------------------------------------------------
// Helper: Execute single expect script payload
// -------------------------------------------------------------------------
function executeExpectScript($script) {
    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];

    $process = proc_open("expect", $descriptorspec, $pipes);

    if (!is_resource($process)) {
        return false;
    }

    fwrite($pipes[0], $script);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    proc_close($process);

    return $stdout;
}

// -------------------------------------------------------------------------
// Helper: Fetch SSH output in 2 isolated sessions (Applications vs Containers)
// -------------------------------------------------------------------------
function fetchCloudAppIdAndContainerViaExpect($host, $user, $password, $appOutputFile, $containerOutputFile) {
    $escapedPassword = addcslashes($password, '"$\\`[]');

    // 1. Fetch Applications in isolated session
    PH::print_stdout("Starting SSH session 1/2: Fetching Applications from $host...");
    $expectAppScript = <<<EXPECT
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

    $rawAppOutput = executeExpectScript($expectAppScript);

    if (empty($rawAppOutput)) {
        PH::print_stdout(" -> SSH Error: Failed to fetch application data.");
        return false;
    }

    // 2. Fetch Containers in isolated session
    PH::print_stdout("Starting SSH session 2/2: Fetching Containers from $host...");
    $expectContainerScript = <<<EXPECT
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
send "show cloud-appid cloud-app-data container all\r"
expect ">"
send "exit\r"
expect eof
EXPECT;

    $rawContainerOutput = executeExpectScript($expectContainerScript);

    if (empty($rawContainerOutput)) {
        PH::print_stdout(" -> SSH Error: Failed to fetch container data.");
        return false;
    }

    // Sanitize and write isolated files
    $cleanAppOutput       = sanitizeTableOutput($rawAppOutput);
    $cleanContainerOutput = sanitizeTableOutput($rawContainerOutput);

    file_put_contents($appOutputFile, $cleanAppOutput);
    file_put_contents($containerOutputFile, $cleanContainerOutput);

    PH::print_stdout(" -> Success! Updated $appOutputFile and $containerOutputFile cleanly via SSH.");
    return true;
}

// -------------------------------------------------------------------------
// Helper: Copy predefined.xml directly via copy()
// -------------------------------------------------------------------------
function checkAndCopyPredefinedXml($sourcePath, $targetFolder, $force = false) {
    PH::print_stdout("Checking and copying 'predefined.xml'...");

    $targetFile = "predefined.xml";

    if (!file_exists($sourcePath)) {
        PH::print_stdout(" -> ERROR: Source file '$sourcePath' does not exist. Aborting copy.");
        return;
    }

    $sourceHash = md5_file($sourcePath);
    $targetHash = file_exists($targetFile) ? md5_file($targetFile) : null;

    if (!$force && $targetHash !== null && $sourceHash === $targetHash) {
        PH::print_stdout(" -> 'predefined.xml' in target directory is already up to date (MD5: $sourceHash).");
        return;
    }

    PH::print_stdout(" -> Copying '$sourcePath' to '$targetFile'...");

    if (copy($sourcePath, $targetFile)) {
        PH::print_stdout(" -> SUCCESS: 'predefined.xml' copied successfully! (MD5: $sourceHash)");
    } else {
        PH::print_stdout(" -> ERROR: Failed to copy 'predefined.xml'. Please check file permissions.");
    }
}

// -------------------------------------------------------------------------
// 1. Predefined XML Copy Check
// -------------------------------------------------------------------------
checkAndCopyPredefinedXml($sourcePredefinedXml, $outputFolder, $forceDownload);

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
PH::print_stdout("Checking Cloud App Version and Timestamp...");

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
            PH::print_stdout("WARNING: Cloud App Version or Timestamp changed!");
            PH::print_stdout("  Previous Version : " . ($previousVersionData['version'] ?? 'N/A') . " | Timestamp: " . ($previousVersionData['timestamp'] ?? 'N/A'));
            PH::print_stdout("  Current Version  : " . ($currentVersionData['version'] ?? 'N/A') . " | Timestamp: " . ($currentVersionData['timestamp'] ?? 'N/A'));
            PH::print_stdout("*******************************************************************");
            PH::print_stdout();

            $targetHost = $pan->connector->apihost;
            $sshUser    = "admin";

            $sshPass = promptHiddenPassword("Enter SSH password for '$sshUser@$targetHost': ");

            $sshSuccess = fetchCloudAppIdAndContainerViaExpect($targetHost, $sshUser, $sshPass, $newTxtFile, $newContainerTxtFile);

            if (!$sshSuccess) {
                PH::print_stdout(" -> SSH update failed. Aborting.");
                exit(1);
            }
        }
        else
        {
            PH::print_stdout();
            PH::print_stdout("*******************************************************************");
            PH::print_stdout("No difference detected in Cloud App Version or Timestamp!");
            PH::print_stdout("*******************************************************************");
            PH::print_stdout();
            exit();
        }
    }

    file_put_contents($versionFile, $currentXmlString);

} catch (Exception $e) {
    PH::print_stdout(" -> Error fetching Cloud App Version: " . $e->getMessage());
}

if (!file_exists($newTxtFile)) {
    derr("Error: New Application index file '$newTxtFile' not found.\n");
}

// -------------------------------------------------------------------------
// Parsing Function for Cloud App / Container Index
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
            strpos($trimmed, 'Invalid syntax') !== false ||
            strpos($trimmed, 'Number of failed attempts') !== false ||
            strpos($trimmed, 'Id ') === 0 ||
            strpos($trimmed, '---') === 0 ||
            strpos($trimmed, 'total ') === 0 ||
            strpos($trimmed, 'Current Time:') === 0 ||
            strpos($trimmed, 'Connection to ') === 0 ||
            preg_match('/^\(admin@[\w\.\-]+\)\s*Password:/i', $trimmed) ||
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

PH::print_stdout("Reading existing index files...");
$oldAppIndex       = parseCloudAppIndex($oldTxtFile);
$newAppIndex       = parseCloudAppIndex($newTxtFile);

$oldContainerIndex = parseCloudAppIndex($oldContainerTxtFile);
$newContainerIndex = parseCloudAppIndex($newContainerTxtFile);

// -------------------------------------------------------------------------
// Calculate Application Diff for API XML Downloads
// -------------------------------------------------------------------------
$appToDownload      = [];
$countNew           = 0;
$countChanged       = 0;
$countMissing       = 0;
$countAlreadyOnDisk = 0;

foreach ($newAppIndex as $id => $newItem) {
    $filePath = $outputFolder . '/' . $id . '.xml';

    if ($forceDownload) {
        $appToDownload[$id] = ['item' => $newItem, 'type' => 'FORCED'];
        $countChanged++;
        continue;
    }

    // Check if the application entry is missing from old index
    if (!isset($oldAppIndex[$id])) {
        // DISK CHECK: If the file already exists locally, skip API call
        if (file_exists($filePath)) {
            PH::print_stdout(" -> Entry $id ({$newItem['name']}) missing in old index, but {$filePath} already exists on disk. Skipping download.");
            $countAlreadyOnDisk++;
            continue;
        }

        $appToDownload[$id] = ['item' => $newItem, 'type' => 'NEW'];
        $countNew++;
        continue;
    }

    // Check if local file was deleted or lost
    if (!file_exists($filePath)) {
        $appToDownload[$id] = ['item' => $newItem, 'type' => 'MISSING'];
        $countMissing++;
        continue;
    }

    // Check if the XML hash changed
    if ($oldAppIndex[$id]['xml_hashcode'] !== $newItem['xml_hashcode']) {
        $appToDownload[$id] = ['item' => $newItem, 'type' => 'CHANGED'];
        $countChanged++;
    }
}

$totalAppDownloads = count($appToDownload);

// -------------------------------------------------------------------------
// Console Summary Output
// -------------------------------------------------------------------------
PH::print_stdout();
PH::print_stdout("Analysis completed:");
PH::print_stdout(" --- Applications ---");
PH::print_stdout(" - Total entries in old index file : " . count($oldAppIndex));
PH::print_stdout(" - Total entries in new index file : " . count($newAppIndex));
PH::print_stdout(" - Total Applications to download  : " . $totalAppDownloads);
PH::print_stdout("   ├─ New App-IDs (to download)    : " . $countNew);
PH::print_stdout("   ├─ Changed App-IDs (hash diff)  : " . $countChanged);
PH::print_stdout("   ├─ Missing XML files            : " . $countMissing);
PH::print_stdout("   └─ Existing on disk (skipped)   : " . $countAlreadyOnDisk);

PH::print_stdout();
PH::print_stdout(" --- Containers ---");
PH::print_stdout(" - Total entries in old index file : " . count($oldContainerIndex));
PH::print_stdout(" - Total entries in new index file : " . count($newContainerIndex));
PH::print_stdout(" - Action                          : Index synchronized cleanly via SSH.");
PH::print_stdout();

// -------------------------------------------------------------------------
// API Download Loop (Applications Only)
// -------------------------------------------------------------------------
$successAppCount = 0;

if ($totalAppDownloads > 0) {
    PH::print_stdout("Starting XML downloads for Applications...");
    foreach ($appToDownload as $id => $entry) {
        $item     = $entry['item'];
        $type     = $entry['type'];
        $name     = $item['name'];
        $filePath = $outputFolder . '/' . $id . '.xml';

        PH::print_stdout("[$type] Downloading App-ID $id ($name)...");

        $apiArgs = Array();
        $apiArgs['type'] = 'op';
        $apiArgs['cmd'] = '<show><cloud-appid><application>' . $name . '</application></cloud-appid></show>';

        try {
            $response = $pan->connector->sendRequest($apiArgs);
            $xmlString = $response->saveXML($response->documentElement);

            if (file_put_contents($filePath, $xmlString) !== false) {
                PH::print_stdout(" -> Successfully saved: " . $filePath);
                $successAppCount++;
            } else {
                PH::print_stdout(" -> Error writing file: " . $filePath);
            }
        } catch (Exception $e) {
            PH::print_stdout(" -> API Error for App $name: " . $e->getMessage());
        }
    }
}

// -------------------------------------------------------------------------
// Synchronize Index Files
// -------------------------------------------------------------------------
if ($totalAppDownloads === 0 || $successAppCount === $totalAppDownloads) {
    PH::print_stdout();
    PH::print_stdout("Application sync successful. Promoting new index files...");
    if (file_exists($newTxtFile)) rename($newTxtFile, $oldTxtFile);
    if (file_exists($newContainerTxtFile)) rename($newContainerTxtFile, $oldContainerTxtFile);
} else {
    PH::print_stdout();
    PH::print_stdout("WARNING: Errors occurred during application XML downloads. New index files kept for retries.");
}

PH::print_stdout();
PH::print_stdout("************* END OF SCRIPT " . basename(__FILE__) . " ************");
PH::print_stdout();