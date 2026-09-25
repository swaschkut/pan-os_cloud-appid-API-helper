<?php
/**
 * ISC License
 *
 * Copyright (c) 2014-2018, Palo Alto Networks Inc.
 * Copyright (c) 2019, Palo Alto Networks Inc.
 * Copyright (c) 2024, Sven Waschkut - pan-os-php@waschkut.net
 *
 * Permission to use, copy, modify, and/or distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 *
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */


set_include_path(dirname(__FILE__) . '/utils/' . PATH_SEPARATOR . get_include_path());
require_once dirname(__FILE__) . "/../pan-os-php/lib/pan_php_framework.php";
require_once dirname(__FILE__) . "/../pan-os-php/utils/lib/UTIL.php";

PH::print_stdout();
PH::print_stdout("***********************************************");
PH::print_stdout("*********** " . basename(__FILE__) . " UTILITY **************");
PH::print_stdout();


PH::print_stdout( "PAN-OS-PHP version: ".PH::frameworkVersion() );


$supportedArguments = Array();
$supportedArguments['in'] = Array('niceName' => 'in', 'shortHelp' => 'input file or api. ie: in=config.xml  or in=api://192.168.1.1 or in=api://0018CAEC3@panorama.company.com', 'argDesc' => '[filename]|[api://IP]|[api://serial@IP]');
$supportedArguments['out'] = Array('niceName' => 'out', 'shortHelp' => 'output file to save config after changes. Only required when input is a file. ie: out=save-config.xml', 'argDesc' => '[filename]');
$supportedArguments['location'] = Array('niceName' => 'location', 'shortHelp' => 'specify if you want to limit your query to a VSYS. By default location=vsys1 for PANOS. ie: location=any or location=vsys2,vsys1', 'argDesc' => '=sub1[,sub2]');
$supportedArguments['debugapi'] = Array('niceName' => 'DebugAPI', 'shortHelp' => 'prints API calls when they happen');
$supportedArguments['help'] = Array('niceName' => 'help', 'shortHelp' => 'this message');
$supportedArguments['loadpanoramapushedconfig'] = Array('niceName' => 'loadPanoramaPushedConfig', 'shortHelp' => 'load Panorama pushed config from the firewall to take in account panorama objects and rules' );
$supportedArguments['folder'] = Array('niceName' => 'folder', 'shortHelp' => 'specify the folder where the offline files should be saved');
// NEU: Force-Download Argument
$supportedArguments['force'] = Array('niceName' => 'force', 'shortHelp' => 'force redownload even if file already exists');


$usageMsg = PH::boldText("USAGE: ")."php ".basename(__FILE__)." in=inputfile.xml location=vsys1 ".
    "custom_url_category=test\n".
    "php ".basename(__FILE__)." help          : more help messages\n";
##############

$util = new UTIL( "custom", $argv, $argc, __FILE__, $supportedArguments, $usageMsg );
$util->utilInit();

##########################################
##########################################

#$util->load_config();
#$util->location_filter();

$pan = $util->pan;
$connector = $pan->connector;


///////////////////////////////////////////////////////

//Todo: no longer working on PAN-OS 10



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
        $names[$columns[0]] = $columns[1];
    }
}


// Zielordner definieren (entweder aus dem CLI-Argument 'folder=' oder Standard 'output')
$outputFolder = isset($util->arguments['folder']) ? $util->arguments['folder'] : 'data';

// Prüfen, ob Ordner existiert, falls nicht -> erstellen
if (!is_dir($outputFolder)) {
    if (!mkdir($outputFolder, 0777, true)) {
        derr("Fehler: Ordner '$outputFolder' konnte nicht erstellt werden.\n");
    }
}


// Prüfen, ob das 'force' Flag übergeben wurde
$forceDownload = isset($util->arguments['force']);

// Array ausgeben
foreach( $names as $id => $name )
{

    // Zielpfad definieren
    $filePath = $outputFolder . '/' . $id . '.xml';

    // Prüfen, ob die Datei bereits existiert und KEIN Force gesetzt ist
    if (file_exists($filePath) && !$forceDownload) {
        PH::print_stdout("Datei existiert bereits, überspringe: " . $filePath);
        continue; // Nächsten Durchlauf starten (kein API-Call)
    }

    $apiArgs = Array();
    $apiArgs['type'] = 'op';


    $query = '<show><cloud-appid><application>'.$name.'</application></cloud-appid></show>';
    $apiArgs = Array();
    $apiArgs['type'] = 'op';
    $apiArgs['cmd'] = $query;





    if( $util->configInput['type'] == 'api' )
        $response = $pan->connector->sendRequest($apiArgs);
    else
        derr( "this script is working only in API mode\n" );


    // DOMDocument als XML-String exportieren
    $xmlString = $response->saveXML($response->documentElement);

    // Dateiname mit der ID (oder $name falls gewünscht) im Zielordner
    $filePath = $outputFolder . '/' . $id . '.xml';

    // XML-Inhalt in die Datei schreiben
    if (file_put_contents($filePath, $xmlString) !== false) {
        PH::print_stdout("Datei erfolgreich gespeichert: " . $filePath);
    } else {
        PH::print_stdout("Fehler beim Speichern von: " . $filePath);
    }
}



##############################################

PH::print_stdout();

// save our work !!!
$util->save_our_work();


PH::print_stdout();
PH::print_stdout("************* END OF SCRIPT " . basename(__FILE__) . " ************" );
PH::print_stdout();
