<?php
ini_set('memory_limit', '-1');

$dbPath = __DIR__ . '/cloud_appid.db';
$schemaPath = __DIR__ . '/schema.sql';
$xmlDir = __DIR__ . '/data'; // Passe den Pfad an, falls deine XMLs woanders liegen (z.B. __DIR__)

// 1. Verbindung herstellen & Schema aus schema.sql laden
$pdo = new PDO("sqlite:$dbPath");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!file_exists($schemaPath)) {
    die("Fehler: schema.sql wurde unter $schemaPath nicht gefunden!\n");
}

$schemaSql = file_get_contents($schemaPath);
$pdo->exec($schemaSql);

// 2. XML-Dateien suchen
$files = glob("$xmlDir/*.xml");
if (empty($files) && file_exists(__DIR__ . '/*.xml')) {
    $files = glob(__DIR__ . '/*.xml');
}

echo "Gefundene XML-Dateien: " . count($files) . "\n";
if (empty($files)) {
    echo "Keine XML-Dateien zum Importieren gefunden.\n";
    exit(0);
}

// Helper-Funktionen für XML Parsing
function getXmlVal($xml, $path, $default = null) {
    $res = $xml->xpath($path);
    return (!empty($res) && isset($res[0])) ? (string)$res[0] : $default;
}

function getXmlBool($xml, $path) {
    $val = strtolower(trim((string)getXmlVal($xml, $path, '')));
    return ($val === 'yes' || $val === 'true' || $val === '1') ? 1 : 0;
}

function getXmlArrayJson($xml, $path) {
    $res = $xml->xpath($path);
    $list = [];
    if (!empty($res)) {
        foreach ($res as $item) {
            $val = trim((string)$item);
            if ($val !== '') {
                $list[] = $val;
            }
        }
    }
    return json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// Prepared Statement basierend auf schema.sql
$sql = "INSERT OR REPLACE INTO cloud_appids (
    name, receiving_time, task_id, xml_hashcode,
    minver, ori_country, ori_language,
    ottawa_name, category, new_category, subcategory, technology, description,
    deny_action, source_type, risk, create_date, last_update_date, application_container,
    appident, vulnerability_ident, evasive_behavior, consume_big_bandwidth, used_by_malware,
    able_to_transfer_file, has_known_vulnerability, tunnel_other_application, prone_to_misuse,
    pervasive_use, per_direction_regex, cachable, cloud_move_to_predefined, is_saas,
    saas_is_data_breaches, saas_is_ip_based_restrictions, saas_is_poor_financial_viability, saas_is_poor_terms_of_service,
    tags, references_json, default_ports, use_applications, tunnel_applications,
    xml_content
) VALUES (
    :name, :receiving_time, :task_id, :xml_hashcode,
    :minver, :ori_country, :ori_language,
    :ottawa_name, :category, :new_category, :subcategory, :technology, :description,
    :deny_action, :source_type, :risk, :create_date, :last_update_date, :application_container,
    :appident, :vulnerability_ident, :evasive_behavior, :consume_big_bandwidth, :used_by_malware,
    :able_to_transfer_file, :has_known_vulnerability, :tunnel_other_application, :prone_to_misuse,
    :pervasive_use, :per_direction_regex, :cachable, :cloud_move_to_predefined, :is_saas,
    :saas_is_data_breaches, :saas_is_ip_based_restrictions, :saas_is_poor_financial_viability, :saas_is_poor_terms_of_service,
    :tags, :references_json, :default_ports, :use_applications, :tunnel_applications,
    :xml_content
)";

$stmt = $pdo->prepare($sql);
$pdo->beginTransaction();

$imported = 0;
foreach ($files as $file) {
    $rawXml = file_get_contents($file);
    if (empty($rawXml)) continue;

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($rawXml);
    if ($xml === false) continue;

    // Entry Tag ermitteln
    $entry = $xml->entry ?? $xml;
    $name = (string)($entry['name'] ?? basename($file, '.xml'));

    // References JSON verarbeiten
    $refs = [];
    if (isset($entry->reference->member)) {
        foreach ($entry->reference->member as $ref) {
            $refs[] = [
                'name' => (string)$ref,
                'link' => (string)($ref['link'] ?? '')
            ];
        }
    }

    $stmt->execute([
        ':name' => $name,
        ':receiving_time' => getXmlVal($xml, '//receiving_time'),
        ':task_id' => getXmlVal($xml, '//task_id'),
        ':xml_hashcode' => getXmlVal($xml, '//xml_hashcode'),

        ':minver' => (string)($entry['minver'] ?? ''),
        ':ori_country' => (string)($entry['ori_country'] ?? ''),
        ':ori_language' => (string)($entry['ori_language'] ?? ''),

        ':ottawa_name' => getXmlVal($entry, './ottawa-name'),
        ':category' => getXmlVal($entry, './category'),
        ':new_category' => getXmlVal($entry, './new-category'),
        ':subcategory' => getXmlVal($entry, './subcategory'),
        ':technology' => getXmlVal($entry, './technology'),
        ':description' => getXmlVal($entry, './description'),
        ':deny_action' => getXmlVal($entry, './deny-action'),
        ':source_type' => getXmlVal($entry, './source-type'),
        ':risk' => (int)getXmlVal($entry, './risk', 0),
        ':create_date' => getXmlVal($entry, './create-date'),
        ':last_update_date' => getXmlVal($entry, './last-update-date'),
        ':application_container' => getXmlVal($entry, './application-container'),

        ':appident' => getXmlBool($entry, './appident'),
        ':vulnerability_ident' => getXmlBool($entry, './vulnerability-ident'),
        ':evasive_behavior' => getXmlBool($entry, './evasive-behavior'),
        ':consume_big_bandwidth' => getXmlBool($entry, './consume-big-bandwidth'),
        ':used_by_malware' => getXmlBool($entry, './used-by-malware'),
        ':able_to_transfer_file' => getXmlBool($entry, './able-to-transfer-file'),
        ':has_known_vulnerability' => getXmlBool($entry, './has-known-vulnerability'),
        ':tunnel_other_application' => getXmlBool($entry, './tunnel-other-application'),
        ':prone_to_misuse' => getXmlBool($entry, './prone-to-misuse'),
        ':pervasive_use' => getXmlBool($entry, './pervasive-use'),
        ':per_direction_regex' => getXmlBool($entry, './per-direction-regex'),
        ':cachable' => getXmlBool($entry, './cachable'),
        ':cloud_move_to_predefined' => getXmlBool($entry, './cloud-move-to-predefined'),
        ':is_saas' => getXmlBool($entry, './is-saas'),

        ':saas_is_data_breaches' => getXmlBool($entry, './saas-risk/data-breaches'),
        ':saas_is_ip_based_restrictions' => getXmlBool($entry, './saas-risk/ip-based-restrictions'),
        ':saas_is_poor_financial_viability' => getXmlBool($entry, './saas-risk/poor-financial-viability'),
        ':saas_is_poor_terms_of_service' => getXmlBool($entry, './saas-risk/poor-terms-of-service'),

        ':tags' => getXmlArrayJson($entry, './tag/member'),
        ':references_json' => json_encode($refs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':default_ports' => getXmlArrayJson($entry, './default/port/member'),
        ':use_applications' => getXmlArrayJson($entry, './use-applications/member'),
        ':tunnel_applications' => getXmlArrayJson($entry, './tunnel-applications/member'),

        ':xml_content' => $rawXml
    ]);

    $imported++;
}

$pdo->commit();
echo "Import erfolgreich abgeschlossen! $imported Einträge in 'cloud_appids' gespeichert.\n";