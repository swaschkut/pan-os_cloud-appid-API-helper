<?php
$dbFile = __DIR__ . '/cloud_appid.db';
$txtFile = __DIR__ . '/cloud-appid.txt';
$dataDir = __DIR__ . '/data';

$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Schema initialisieren
$db->exec(file_get_contents(__DIR__ . '/schema.sql'));

// 2. cloud-appid.txt parsen für Index-Zuordnung (Name, Time, Task, Hash)
echo "Lese cloud-appid.txt ein...\n";
$indexMap = [];
$lines = file($txtFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $line) {
    $line = trim($line);
    if (strpos($line, 'Id') === 0 || strpos($line, '---') === 0) continue;
    $columns = preg_split('/\s{2,}/', $line);
    if (count($columns) >= 5) {
        $id = (int)trim($columns[0]);
        $indexMap[$id] = [
            'name'           => trim($columns[1]),
            'receiving_time' => trim($columns[2]),
            'task_id'        => (int)trim($columns[3]),
            'xml_hashcode'   => trim($columns[4])
        ];
    }
}

// Helper für Boolean-Werte
$toBool = function($val) {
    if ($val === null) return null;
    $str = strtolower(trim((string)$val));
    return ($str === 'yes' || $str === '1' || $str === 'true') ? 1 : 0;
};

// Helper zum Extrahieren von Member-Listen
$getMembers = function($element) {
    if (!$element) return null;
    $members = [];
    foreach ($element->xpath('.//member') as $m) {
        $members[] = (string)$m;
    }
    return !empty($members) ? json_encode($members) : null;
};

// 3. Prepared Insert Statement
$sql = "INSERT INTO cloud_appids (
    id, name, receiving_time, task_id, xml_hashcode, minver, ori_country, ori_language,
    ottawa_name, category, new_category, subcategory, technology, description, deny_action,
    source_type, risk, create_date, last_update_date, application_container,
    appident, vulnerability_ident, evasive_behavior, consume_big_bandwidth, used_by_malware,
    able_to_transfer_file, has_known_vulnerability, tunnel_other_application, prone_to_misuse,
    pervasive_use, per_direction_regex, cachable, cloud_move_to_predefined, is_saas,
    saas_is_data_breaches, saas_is_ip_based_restrictions, saas_is_poor_financial_viability, saas_is_poor_terms_of_service,
    tags, references_json, default_ports, use_applications, tunnel_applications, xml_content
) VALUES (
    :id, :name, :receiving_time, :task_id, :xml_hashcode, :minver, :ori_country, :ori_language,
    :ottawa_name, :category, :new_category, :subcategory, :technology, :description, :deny_action,
    :source_type, :risk, :create_date, :last_update_date, :application_container,
    :appident, :vulnerability_ident, :evasive_behavior, :consume_big_bandwidth, :used_by_malware,
    :able_to_transfer_file, :has_known_vulnerability, :tunnel_other_application, :prone_to_misuse,
    :pervasive_use, :per_direction_regex, :cachable, :cloud_move_to_predefined, :is_saas,
    :saas_is_data_breaches, :saas_is_ip_based_restrictions, :saas_is_poor_financial_viability, :saas_is_poor_terms_of_service,
    :tags, :references_json, :default_ports, :use_applications, :tunnel_applications, :xml_content
) ON CONFLICT(id) DO UPDATE SET xml_content = excluded.xml_content";

$stmt = $db->prepare($sql);

$files = glob($dataDir . '/*.xml');
echo "Starte Import von " . count($files) . " lokalen XML-Dateien...\n";

$db->beginTransaction();
$importedCount = 0;

foreach ($files as $filePath) {
    $id = (int)pathinfo($filePath, PATHINFO_FILENAME);
    $rawXml = file_get_contents($filePath);
    if (!$rawXml) continue;

    $xml = @simplexml_load_string($rawXml);
    if (!$xml) continue;

    // Suche nach <entry>
    $entry = $xml->xpath('//entry')[0] ?? null;
    if (!$entry) continue;

    // Fallback-Meta aus TXT oder XML Attributes holen
    $meta = $indexMap[$id] ?? [
        'name'           => (string)$entry['name'],
        'receiving_time' => null,
        'task_id'        => null,
        'xml_hashcode'   => null
    ];

    // References zu JSON verarbeiten
    $refs = [];
    if (isset($entry->references)) {
        foreach ($entry->references->entry as $ref) {
            $refs[] = [
                'name' => (string)$ref['name'],
                'link' => (string)$ref->link
            ];
        }
    }

    $stmt->execute([
        ':id'                           => $id,
        ':name'                         => $meta['name'],
        ':receiving_time'               => $meta['receiving_time'],
        ':task_id'                      => $meta['task_id'],
        ':xml_hashcode'                 => $meta['xml_hashcode'],
        ':minver'                       => (string)$entry['minver'] ?: null,
        ':ori_country'                  => (string)$entry['ori_country'] ?: null,
        ':ori_language'                 => (string)$entry['ori_language'] ?: null,
        ':ottawa_name'                  => (string)$entry->{'ottawa-name'} ?: null,
        ':category'                     => (string)$entry->category ?: null,
        ':new_category'                 => (string)$entry->{'new-category'} ?: null,
        ':subcategory'                  => (string)$entry->subcategory ?: null,
        ':technology'                   => (string)$entry->technology ?: null,
        ':description'                  => (string)$entry->description ?: null,
        ':deny_action'                  => (string)$entry->{'deny-action'} ?: null,
        ':source_type'                  => (string)$entry->{'source-type'} ?: null,
        ':risk'                         => isset($entry->risk) ? (int)$entry->risk : null,
        ':create_date'                  => (string)$entry->{'create-date'} ?: null,
        ':last_update_date'             => (string)$entry->{'last-update-date'} ?: null,
        ':application_container'        => (string)$entry->{'application-container'} ?: null,
        ':appident'                     => $toBool($entry->appident),
        ':vulnerability_ident'          => $toBool($entry->{'vulnerability-ident'}),
        ':evasive_behavior'             => $toBool($entry->{'evasive-behavior'}),
        ':consume_big_bandwidth'        => $toBool($entry->{'consume-big-bandwidth'}),
        ':used_by_malware'              => $toBool($entry->{'used-by-malware'}),
        ':able_to_transfer_file'        => $toBool($entry->{'able-to-transfer-file'}),
        ':has_known_vulnerability'      => $toBool($entry->{'has-known-vulnerability'}),
        ':tunnel_other_application'     => $toBool($entry->{'tunnel-other-application'}),
        ':prone_to_misuse'              => $toBool($entry->{'prone-to-misuse'}),
        ':pervasive_use'                => $toBool($entry->{'pervasive-use'}),
        ':per_direction_regex'          => $toBool($entry->{'per-direction-regex'}),
        ':cachable'                     => $toBool($entry->cachable),
        ':cloud_move_to_predefined'     => $toBool($entry->{'cloud-move-to-predefined'}),
        ':is_saas'                      => $toBool($entry->{'is-saas'}),
        ':saas_is_data_breaches'        => isset($entry->saas) ? $toBool($entry->saas->{'is-data-breaches'}) : null,
        ':saas_is_ip_based_restrictions' => isset($entry->saas) ? $toBool($entry->saas->{'is-ip-based-restrictions'}) : null,
        ':saas_is_poor_financial_viability' => isset($entry->saas) ? $toBool($entry->saas->{'is-poor-financial-viability'}) : null,
        ':saas_is_poor_terms_of_service' => isset($entry->saas) ? $toBool($entry->saas->{'is-poor-terms-of-service'}) : null,
        ':tags'                         => $getMembers($entry->tag ?? null),
        ':references_json'              => !empty($refs) ? json_encode($refs) : null,
        ':default_ports'                => $getMembers($entry->default->port ?? null),
        ':use_applications'             => $getMembers($entry->{'use-applications'} ?? null),
        ':tunnel_applications'          => $getMembers($entry->{'tunnel-applications'} ?? null),
        ':xml_content'                  => $rawXml
    ]);

    $importedCount++;
    if ($importedCount % 5000 === 0) echo "Importiert: $importedCount Datein...\n";
}

$db->commit();
echo "FERTIG! $importedCount vorhandene Dateien erfolgreich in 'cloud_appid.db' eingelesen.\n";