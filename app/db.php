<?php

function get_db(): SQLite3 {
    $config = require __DIR__ . '/config.php';
    $dir = dirname($config['db_path']);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $db = new SQLite3($config['db_path']);
    $db->enableExceptions(true);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');

    init_schema($db);
    return $db;
}

function add_column_if_missing(SQLite3 $db, string $table, string $column, string $definition): void {
    $result = $db->query("PRAGMA table_info({$table})");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === $column) return;
    }
    $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}

function init_schema(SQLite3 $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS participants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            prolific_pid TEXT UNIQUE NOT NULL,
            source TEXT NOT NULL DEFAULT 'web',
            condition_num INTEGER NOT NULL,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME,
            completion_code TEXT NOT NULL,
            pss4_q1 INTEGER,
            pss4_q2 INTEGER,
            pss4_q3 INTEGER,
            pss4_q4 INTEGER,
            pss4_sum INTEGER,
            rounds_taken INTEGER
        );

        CREATE TABLE IF NOT EXISTS responses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            participant_id INTEGER NOT NULL,
            step TEXT NOT NULL,
            prompt_shown TEXT NOT NULL,
            response_text TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (participant_id) REFERENCES participants(id)
        );

        CREATE TABLE IF NOT EXISTS gene_extractions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            participant_id INTEGER NOT NULL,
            round INTEGER NOT NULL,
            technique TEXT,
            dosage TEXT,
            mode TEXT,
            technique_confidence TEXT,
            dosage_confidence TEXT,
            mode_confidence TEXT,
            targeted_dimension TEXT,
            ai_question TEXT,
            raw_llm_response TEXT,
            gate_decision TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (participant_id) REFERENCES participants(id)
        );

        CREATE TABLE IF NOT EXISTS questionnaire (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            participant_id INTEGER NOT NULL,
            semantic_fidelity INTEGER,
            forced_fit INTEGER,
            willingness INTEGER,
            interest INTEGER,
            context_text TEXT,
            outcome_text TEXT,
            fidelity_feedback TEXT,
            general_feedback TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (participant_id) REFERENCES participants(id)
        );
    ");

    // Migrations for existing databases (idempotent: ALTER TABLE only if column missing)
    add_column_if_missing($db, 'participants', 'pss4_q1', 'INTEGER');
    add_column_if_missing($db, 'participants', 'pss4_q2', 'INTEGER');
    add_column_if_missing($db, 'participants', 'pss4_q3', 'INTEGER');
    add_column_if_missing($db, 'participants', 'pss4_q4', 'INTEGER');
    add_column_if_missing($db, 'participants', 'pss4_sum', 'INTEGER');
    add_column_if_missing($db, 'participants', 'gad2_q1', 'INTEGER');
    add_column_if_missing($db, 'participants', 'gad2_q2', 'INTEGER');
    add_column_if_missing($db, 'participants', 'gad2_sum', 'INTEGER');
    add_column_if_missing($db, 'participants', 'rounds_taken', 'INTEGER');
    add_column_if_missing($db, 'gene_extractions', 'gate_decision', 'TEXT');
    add_column_if_missing($db, 'gene_extractions', 'technique_level', 'INTEGER');
    add_column_if_missing($db, 'gene_extractions', 'dosage_level', 'INTEGER');
    add_column_if_missing($db, 'gene_extractions', 'mode_level', 'INTEGER');
    add_column_if_missing($db, 'gene_extractions', 'description_snapshot', 'TEXT');
}
