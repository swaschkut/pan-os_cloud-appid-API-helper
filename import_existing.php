<?php
ini_set('memory_limit', '-1');

$dbPath = __DIR__ . '/cloud_appid.db';
$schemaPath = __DIR__ . '/schema.sql';$xmlDir = __DIR__ . '/data';

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
if (empty($files) && file_exists(__DIR__ . '/*.xml')) {$files = glob(__DIR__ . '/*.xml');
}

echo "Gefundene XML-Dateien: " . count($files) . "\n";
if (empty($files)) {
    echo "Keine XML-Dateien zum Importieren gefunden.\n";
    exit(0);
}

// Helper-Funktionen für XML Parsing
function getXmlBool($node, $path = null) {
    if (!$node) return 0;
    if ($path !== null) {
        $res = $node->xpath($path);
        $val = (!empty($res) && isset($res[0])) ? strtolower(trim((string)$res[0])) : '';
    } else {
        $val = strtolower(trim((string)$node));
    }
    return ($val === 'yes' || $val === 'true' || $val === '1') ? 1 : 0;
}

function getXmlArrayJson($node,$xpathExpr) {
    if (!$node) return json_encode([]);
    $res =$node->xpath($xpathExpr);$list = [];
    if (!empty($res)) {
        foreach ($res as$item) {
            $val = trim((string)$item);
            if ($val !== '') {
                $list[] =$val;
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

$stmt =$pdo->prepare($sql);$pdo->beginTransaction();

$imported = 0;
foreach ($files as$file) {
    $rawXml = file_get_contents($file);
    if (empty($rawXml)) continue;

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($rawXml);
    if ($xml === false) continue;

    // Directen <entry>-Knoten aus /response/result/entry extrahieren
    $entries = $xml->xpath('//result/entry') ?:$xml->xpath('//entry');
    if (empty($entries)) continue;
    $entry =$entries[0];

    // Name direkt aus Attribut `name` auslesen (z.B. "oracle-analytics-clo-base")
    $name = (string)($entry['name'] ?? basename($file, '.xml'));

    // References extrahieren aus <references><entry name="...">
    $refs = [];
    if (isset($entry->references->entry)) {
        foreach ($entry->references->entry as $refEntry) {$refs[] = [
            'name' => (string)($refEntry['name'] ?? ''),
            'link' => (string)($refEntry->link ?? '')
        ];
        }
    }

    // SaaS-Knoten als Referenz für SaaS-Risiken
    $saasNode =$entry->saas ?? null;

    $stmt->execute([
        ':name' => $name,
        ':receiving_time' => (string)($xml->receiving_time ?? ''),
        ':task_id' => (string)($xml->task_id ?? ''),
        ':xml_hashcode' => (string)($xml->xml_hashcode ?? ''),

        ':minver' => (string)($entry['minver'] ?? ''),
        ':ori_country' => (string)($entry['ori_country'] ?? ''),
        ':ori_language' => (string)($entry['ori_language'] ?? ''),

        ':ottawa_name' => (string)($entry->{'ottawa-name'} ?? ''),
        ':category' => (string)($entry->category ?? ''),
        ':new_category' => (string)($entry->{'new-category'} ?? ''),
        ':subcategory' => (string)($entry->subcategory ?? ''),
        ':technology' => (string)($entry->technology ?? ''),
        ':description' => (string)($entry->description ?? ''),
        ':deny_action' => (string)($entry->{'deny-action'} ?? ''),
        ':source_type' => (string)($entry->{'source-type'} ?? ''),
        ':risk' => (int)($entry->risk ?? 0),
        ':create_date' => (string)($entry->{'create-date'} ?? ''),
        ':last_update_date' => (string)($entry->{'last-update-date'} ?? ''),
        ':application_container' => (string)($entry->{'application-container'} ?? ''),

        // Bools direct am Entry Node
        ':appident' => getXmlBool($entry->appident ?? null),
        ':vulnerability_ident' => getXmlBool($entry->{'vulnerability-ident'} ?? null),
        ':evasive_behavior' => getXmlBool($entry->{'evasive-behavior'} ?? null),
        ':consume_big_bandwidth' => getXmlBool($entry->{'consume-big-bandwidth'} ?? null),
        ':used_by_malware' => getXmlBool($entry->{'used-by-malware'} ?? null),
        ':able_to_transfer_file' => getXmlBool($entry->{'able-to-transfer-file'} ?? null),
        ':has_known_vulnerability' => getXmlBool($entry->{'has-known-vulnerability'} ?? null),
        ':tunnel_other_application' => getXmlBool($entry->{'tunnel-other-application'} ?? null),
        ':prone_to_misuse' => getXmlBool($entry->{'prone-to-misuse'} ?? null),
        ':pervasive_use' => getXmlBool($entry->{'pervasive-use'} ?? null),
        ':per_direction_regex' => getXmlBool($entry->{'per-direction-regex'} ?? null),
        ':cachable' => getXmlBool($entry->cachable ?? null),
        ':cloud_move_to_predefined' => getXmlBool($entry->{'cloud-move-to-predefined'} ?? null),
        ':is_saas' => getXmlBool($entry->{'is-saas'} ?? null),

        // SaaS Subknoten (<saas><is-data-breaches>...</saas>)
        ':saas_is_data_breaches' => getXmlBool($saasNode->{'is-data-breaches'} ?? null),
        ':saas_is_ip_based_restrictions' => getXmlBool($saasNode->{'is-ip-based-restrictions'} ?? null),
        ':saas_is_poor_financial_viability' => getXmlBool($saasNode->{'is-poor-financial-viability'} ?? null),
        ':saas_is_poor_terms_of_service' => getXmlBool($saasNode->{'is-poor-terms-of-service'} ?? null),

        // JSON Arrays
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