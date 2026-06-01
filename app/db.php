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

function rename_column_if_exists(SQLite3 $db, string $table, string $old, string $new): void {
    $has_old = false; $has_new = false;
    $result = $db->query("PRAGMA table_info({$table})");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === $old) $has_old = true;
        if ($row['name'] === $new) $has_new = true;
    }
    if ($has_old && !$has_new) {
        $db->exec("ALTER TABLE {$table} RENAME COLUMN {$old} TO {$new}");
    }
}

function drop_column_if_exists(SQLite3 $db, string $table, string $column): void {
    $found = false;
    $result = $db->query("PRAGMA table_info({$table})");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === $column) { $found = true; break; }
    }
    if (!$found) return;
    try {
        $db->exec("ALTER TABLE {$table} DROP COLUMN {$column}");
    } catch (\Exception $e) {
        // SQLite < 3.35 cannot drop columns. The app stops writing to it; leave orphaned.
    }
}

function rename_table_if_exists(SQLite3 $db, string $old, string $new): void {
    $has_old = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='{$old}'") !== null;
    $has_new = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='{$new}'") !== null;
    if ($has_old && !$has_new) {
        $db->exec("ALTER TABLE {$old} RENAME TO {$new}");
    }
}

function init_schema(SQLite3 $db): void {
    // Pre-migrate: rename the legacy `gene_extractions` table to `practice_extractions`
    // before CREATE so we never end up with two tables.
    rename_table_if_exists($db, 'gene_extractions', 'practice_extractions');

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

        CREATE TABLE IF NOT EXISTS practice_extractions (
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
            self_distortion INTEGER,
            willingness INTEGER,
            context_text TEXT,
            outcome_text TEXT,
            fidelity_feedback TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (participant_id) REFERENCES participants(id)
        );
    ");

    // Migrations for existing databases (idempotent).
    rename_column_if_exists($db, 'questionnaire', 'forced_fit', 'self_distortion');
    drop_column_if_exists($db, 'questionnaire', 'interest');
    drop_column_if_exists($db, 'questionnaire', 'general_feedback');

    add_column_if_missing($db, 'participants', 'pss4_q1', 'INTEGER');
    add_column_if_missing($db, 'participants', 'pss4_q2', 'INTEGER');
    add_column_if_missing($db, 'participants', 'pss4_q3', 'INTEGER');
    add_column_if_missing($db, 'participants', 'pss4_q4', 'INTEGER');
    add_column_if_missing($db, 'participants', 'pss4_sum', 'INTEGER');
    add_column_if_missing($db, 'participants', 'gad2_q1', 'INTEGER');
    add_column_if_missing($db, 'participants', 'gad2_q2', 'INTEGER');
    add_column_if_missing($db, 'participants', 'gad2_sum', 'INTEGER');
    add_column_if_missing($db, 'participants', 'rounds_taken', 'INTEGER');
    add_column_if_missing($db, 'practice_extractions', 'gate_decision', 'TEXT');
    add_column_if_missing($db, 'practice_extractions', 'technique_level', 'INTEGER');
    add_column_if_missing($db, 'practice_extractions', 'dosage_level', 'INTEGER');
    add_column_if_missing($db, 'practice_extractions', 'mode_level', 'INTEGER');
    add_column_if_missing($db, 'practice_extractions', 'description_snapshot', 'TEXT');
    // Embedded instructional-manipulation check on the exploratory step.
    // Raw 1-7 Likert; pass criterion = 1 ("Strongly disagree").
    add_column_if_missing($db, 'questionnaire', 'attention_check', 'INTEGER');

    // Post-hoc specificity coding facility (crowd raters via Prolific Taskflow +
    // optional multi-model LLM coding). One coding_task = one blinded text to score
    // on the T/D/M 0-3 rubric. Each coding row holds one rater's (human or model) scores.
    $db->exec("
        CREATE TABLE IF NOT EXISTS coding_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT UNIQUE NOT NULL,
            participant_id INTEGER NOT NULL,
            condition_num INTEGER NOT NULL,
            text_role TEXT NOT NULL,          -- 'single' | 'c3_first' | 'c3_final'
            text_content TEXT NOT NULL,
            target_raters INTEGER NOT NULL DEFAULT 3,  -- paper: >=3 raters/response, modal label
            last_served_at INTEGER,           -- unix seconds the router last handed this task out (soft reservation)
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (participant_id, text_role),
            FOREIGN KEY (participant_id) REFERENCES participants(id)
        );

        CREATE TABLE IF NOT EXISTS codings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            source TEXT NOT NULL,             -- 'human' | model slug (e.g. 'anthropic/claude-sonnet-4.6')
            rater_pid TEXT,                   -- Prolific PID for humans; null for models
            session_id TEXT,
            technique INTEGER,                -- 0-3 (scored on the PRIMARY practice)
            dosage INTEGER,                   -- 0-3
            mode INTEGER,                     -- 0-3
            technique_count INTEGER,          -- distinct techniques in the report (1, 2, 3=3+)
            coding_seconds INTEGER,           -- humans: server-side seconds from task render to submit (best-effort)
            notes TEXT,
            raw_llm_response TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES coding_tasks(id)
        );
        CREATE INDEX IF NOT EXISTS idx_codings_task ON codings(task_id);

        CREATE TABLE IF NOT EXISTS coding_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT,
            model TEXT NOT NULL,
            instructions TEXT NOT NULL,
            output_contract TEXT NOT NULL,
            full_prompt TEXT NOT NULL,
            temperature REAL,
            status TEXT NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
    // Migration for DBs whose codings table predates the multiplicity count.
    add_column_if_missing($db, 'codings', 'technique_count', 'INTEGER');
    // Migration for DBs predating server-side rating-duration capture.
    add_column_if_missing($db, 'codings', 'coding_seconds', 'INTEGER');
    // Migration for DBs predating the router soft-reservation column.
    add_column_if_missing($db, 'coding_tasks', 'last_served_at', 'INTEGER');
    // Migration for DBs predating run-tracked LLM coding.
    add_column_if_missing($db, 'codings', 'run_id', 'INTEGER');
    // At most one model coding per (run, task). Remove pre-index duplicates created by
    // overlapping batch clicks (keeping the earliest), then enforce uniqueness so a
    // double-submitted batch becomes a no-op instead of overshooting coverage.
    // Human codings have run_id NULL (multiple raters per task) and are unaffected.
    $db->exec("DELETE FROM codings WHERE run_id IS NOT NULL AND id NOT IN (
                 SELECT MIN(id) FROM codings WHERE run_id IS NOT NULL GROUP BY run_id, task_id)");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_codings_run_task
                 ON codings(run_id, task_id) WHERE run_id IS NOT NULL");
}
