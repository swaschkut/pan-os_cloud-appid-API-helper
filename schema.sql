CREATE TABLE IF NOT EXISTS cloud_appids (
    -- Primary Keys & Meta aus cloud-appid.txt
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    receiving_time TEXT,
    task_id INTEGER,
    xml_hashcode TEXT,

    -- Attributes aus <entry> Tag
    minver TEXT,
    ori_country TEXT,
    ori_language TEXT,

    -- Single Value Tags
    ottawa_name TEXT,
    category TEXT,
    new_category TEXT,
    subcategory TEXT,
    technology TEXT,
    description TEXT,
    deny_action TEXT,
    source_type TEXT,
    risk INTEGER,
    create_date TEXT,
    last_update_date TEXT,
    application_container TEXT,

    -- Booleans (1 / 0)
    appident INTEGER,
    vulnerability_ident INTEGER,
    evasive_behavior INTEGER,
    consume_big_bandwidth INTEGER,
    used_by_malware INTEGER,
    able_to_transfer_file INTEGER,
    has_known_vulnerability INTEGER,
    tunnel_other_application INTEGER,
    prone_to_misuse INTEGER,
    pervasive_use INTEGER,
    per_direction_regex INTEGER,
    cachable INTEGER,
    cloud_move_to_predefined INTEGER,
    is_saas INTEGER,

    -- Nested SaaS Booleans (1 / 0)
    saas_is_data_breaches INTEGER,
    saas_is_ip_based_restrictions INTEGER,
    saas_is_poor_financial_viability INTEGER,
    saas_is_poor_terms_of_service INTEGER,

    -- Complex Array / List Fields (gespeichert als JSON)
    tags TEXT,                  -- ["App-ID Cloud Engine", "Web App"]
    references_json TEXT,       -- [{"name": "paid", "link": "http://..."}]
    default_ports TEXT,         -- ["tcp/80,443"]
    use_applications TEXT,      -- ["ssl", "web-browsing"]
    tunnel_applications TEXT,   -- ["paid-logout"]

    -- Full Raw Response
    xml_content TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indizes für häufig gesuchte Felder
CREATE INDEX IF NOT EXISTS idx_name ON cloud_appids(name);
CREATE INDEX IF NOT EXISTS idx_risk ON cloud_appids(risk);
CREATE INDEX IF NOT EXISTS idx_category ON cloud_appids(category);
CREATE INDEX IF NOT EXISTS idx_technology ON cloud_appids(technology);