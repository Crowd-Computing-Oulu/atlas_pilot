# llm_coder.R --------------------------------------------------------------
# LLM specificity coding for the ATLAS Pilot analysis set.
#
# Three frontier LLMs (one per lab) each code the 404 blinded tasks once, at
# temperature 0. The prompt below MIRRORS THE HUMAN RATER WEBSITE (app/code.php):
# same title, same instructions, same dimension labels and 0-3 anchors, same
# multi-practice rule, same "how many distinct practices" question. The model is
# asked to return exactly what a human rater submits, four values (T, D, M each
# 0-3, plus technique_count 1/2/3), and nothing else. No coaching hints, no
# evidence phrases, because the human raters produce none. This makes the LLM
# panel a true parallel annotator pool to the human panel on an identical task.
#
# Design mirrors the production batch coder: idempotent and restartable. It
# only codes tasks not already present in OUT_PATH, and rewrites the CSV after
# each task, so an interrupted run loses nothing and re-running tops up the gap.
# This is what prevents the duplicate/overshoot problem at the data level: one
# row per task_id, keyed on what is already on disk.
#
# Run directly to code:   cd analysis && Rscript coding/llm_coder.R
# Or source() it and call code_all() from the notebook.
# Requires OPENROUTER_API_KEY in the environment.

suppressMessages({
  library(DBI)
  library(RSQLite)
  library(jsonlite)
})

LLM_MODEL       <- "anthropic/claude-sonnet-4.6"
LLM_TEMPERATURE <- 0
# Safety ceiling on output length, not a semantic knob. Reasoning-model coders
# (e.g. Gemini 3.1 Pro) spend tokens on a hidden reasoning field before emitting
# the JSON, so the ceiling must leave room for both or the JSON gets truncated
# (finish_reason = length). 8000 is ample for one short rubric verdict. Sonnet's
# completed run used 1024 with zero truncations, so its codings are unaffected.
LLM_MAX_TOKENS  <- 8000L
DB_PATH         <- "data/final_prod/final_atlas_pilot_database.db"
OUT_PATH        <- "data/llm_codings.csv"

# --- Prompt mirroring the human rater website (app/code.php) ----------------
# Title, instructions, dimension labels, 0-3 anchors and the distinct-practices
# question are copied verbatim from what crowd raters saw on the coding screen.
CODING_INSTRUCTIONS <- r"{Rate how specifically this text describes a self-care practice.

A person was asked to describe a practice they use when feeling stressed or anxious. Read their description, then rate how specific it is on three dimensions using the guides. Rate only what is written; do not guess what they might have meant.

If the text describes more than one practice (for example breathing and a walk), rate the main practice only: the one the writer leads with or describes in the most detail. You will note how many separate practices there are in the last question.

Technique — what the person actually does
 0 — Absent — no technique mentioned
 1 — Category only — a broad family (e.g. "relaxation", "exercise")
 2 — Named — a specific named practice (e.g. "box breathing", "running")
 3 — Parameterised — a named practice with defining parameters (e.g. "4-4-4-4 box breathing")

Dosage — the magnitude or extent of the practice
 0 — Absent — no information about magnitude or extent
 1 — Vague — non-quantified (e.g. "sometimes", "a bit", "when I need it")
 2 — Single anchor — one dose anchor of any kind (e.g. "20 minutes", "5 cycles", "3x per week", or "until I feel calmer")
 3 — Multiple anchors — two or more such anchors (e.g. "20 min, 3x per week")

Mode — how the practice is enacted (not when/why it is used)
 0 — Absent — no information about how it is enacted
 1 — Vague — minimal detail (e.g. "by myself", "with help")
 2 — Specified — a clear mode descriptor (e.g. "solo", "in a group", "with an app", "unguided")
 3 — Operationalised — mode plus a specific delivery mechanism (e.g. "solo using a meditation app for guidance")

How many distinct practices does the text describe? Count separate things the person does (e.g. breathing and a walk = 2). You rated only the main one above. Use 1, 2, or 3, where 3 means three or more.}"

CODING_OUTPUT_CONTRACT <- 'Respond ONLY with valid JSON in exactly this format. Each of technique, dosage and mode is an integer 0-3 from the guides; technique_count is an integer 1, 2, or 3 (3 means three or more):
{"technique": 0, "dosage": 0, "mode": 0, "technique_count": 1}'

SYSTEM_PROMPT <- paste0(CODING_INSTRUCTIONS, "\n\n", CODING_OUTPUT_CONTRACT)

`%||%` <- function(a, b) if (is.null(a)) b else a
clampi <- function(x, lo, hi) as.integer(max(lo, min(hi, x)))

# Resolve the OpenRouter key WITHOUT ever putting it in a notebook. In order:
#   1. the OPENROUTER_API_KEY environment variable;
#   2. the gitignored project-root .env (../.env when rendering from analysis/),
#      the same file notebook 04 reads, so the key has one home;
#   3. a gitignored coding/.openrouter_key file, if you prefer a dedicated secret.
KEY_FILE <- "coding/.openrouter_key"
ENV_FILE <- "../.env"
.read_env_key <- function(path = ENV_FILE) {
  if (!file.exists(path)) return("")
  hit <- grep("^\\s*OPENROUTER_API_KEY\\s*=", readLines(path, warn = FALSE), value = TRUE)
  if (!length(hit)) return("")
  val <- sub("^[^=]*=", "", hit[[1]])
  gsub("^['\"]|['\"]$", "", trimws(val))     # strip surrounding quotes/space
}
api_key <- function() {
  k <- Sys.getenv("OPENROUTER_API_KEY")
  if (!nzchar(k)) k <- .read_env_key()
  if (!nzchar(k) && file.exists(KEY_FILE)) k <- trimws(readLines(KEY_FILE, warn = FALSE)[1])
  k
}

load_tasks <- function(db = DB_PATH) {
  con <- dbConnect(RSQLite::SQLite(), db)
  on.exit(dbDisconnect(con))
  dbGetQuery(con, "SELECT id AS task_id, condition_num, text_role, text_content
                   FROM coding_tasks ORDER BY id")
}

# One output CSV per model, e.g. data/llm_codings_openai_gpt-5.5.csv. The slug's
# "/" is sanitised so each coder's ratings live in their own file and never mix.
out_path_for <- function(model) file.path("data",
  paste0("llm_codings_", gsub("[/:]+", "_", model), ".csv"))

# Code one description with a given model. Returns a one-row data.frame, or NULL.
code_one <- function(text, model = LLM_MODEL) {
  body <- list(
    model = model, max_tokens = LLM_MAX_TOKENS, temperature = LLM_TEMPERATURE,
    messages = list(
      list(role = "system", content = SYSTEM_PROMPT),
      list(role = "user",   content = paste0("Description: ", text))
    )
  )
  bf <- tempfile(fileext = ".json"); on.exit(unlink(bf))
  writeLines(toJSON(body, auto_unbox = TRUE, null = "null"), bf)
  # system2 pastes args into a shell string WITHOUT quoting, so header values
  # that contain spaces (e.g. "Content-Type: application/json") must be shQuote'd
  # or curl mis-parses them as extra URLs. suppressWarnings so a nonzero curl
  # exit never echoes the command line (which carries the bearer token).
  args <- c("-s", "--max-time", "90",
            shQuote("https://openrouter.ai/api/v1/chat/completions"),
            "-H", shQuote("Content-Type: application/json"),
            "-H", shQuote(paste0("Authorization: Bearer ", api_key())),
            "-H", shQuote("X-Title: ATLAS Pilot"),
            "--data", shQuote(paste0("@", bf)))
  raw <- suppressWarnings(tryCatch(
    system2("curl", args, stdout = TRUE, stderr = FALSE),
    error = function(e) character(0)))
  raw <- paste(raw, collapse = "\n")
  if (!nzchar(raw)) return(NULL)
  top <- tryCatch(fromJSON(raw, simplifyVector = FALSE), error = function(e) NULL)
  content <- tryCatch(top$choices[[1]]$message$content, error = function(e) NULL)
  if (is.null(content) || !nzchar(content)) return(NULL)
  m <- regmatches(content, regexpr("\\{[\\s\\S]*\\}", content, perl = TRUE))
  if (length(m) == 0) return(NULL)
  obj <- tryCatch(fromJSON(m, simplifyVector = FALSE), error = function(e) NULL)
  if (is.null(obj) || is.null(obj$technique) || is.null(obj$dosage) || is.null(obj$mode))
    return(NULL)
  # Accept the flat form {"technique": 0, ...}; tolerate a stray nested
  # {"technique": {"level": 0}} so one model's formatting quirk can't kill a run.
  lvl <- function(x) { v <- if (is.list(x)) (x$level %||% NA) else x; clampi(as.integer(v %||% 0L), 0L, 3L) }
  data.frame(
    technique = lvl(obj$technique), dosage = lvl(obj$dosage), mode = lvl(obj$mode),
    technique_count = clampi(as.integer(obj$technique_count %||% 1L), 1L, 3L),
    stringsAsFactors = FALSE)
}

# Code every task not yet coded by `model`. Idempotent and restartable: writes
# one row per task_id to that model's own CSV, checkpointing after each call, so
# there is no way to duplicate a task or overshoot 404.
code_all <- function(model = LLM_MODEL, out_path = out_path_for(model), verbose = TRUE) {
  if (!nzchar(api_key()))
    stop("No OpenRouter key: set OPENROUTER_API_KEY, add it to ", ENV_FILE,
         " (OPENROUTER_API_KEY=...), or create ", KEY_FILE)
  tasks <- load_tasks()
  done  <- if (file.exists(out_path)) read.csv(out_path, stringsAsFactors = FALSE) else NULL
  results <- if (!is.null(done)) done else data.frame()
  done_ids <- if (!is.null(done)) done$task_id else integer(0)
  pending  <- tasks[!tasks$task_id %in% done_ids, , drop = FALSE]
  if (verbose) message(sprintf("[%s] Tasks: %d total, %d already coded, %d pending.",
                               model, nrow(tasks), length(done_ids), nrow(pending)))
  if (nrow(pending) == 0) return(invisible(results))
  for (i in seq_len(nrow(pending))) {
    tk <- pending[i, ]
    res <- NULL
    for (attempt in 1:2) { res <- code_one(tk$text_content, model); if (!is.null(res)) break }
    if (is.null(res)) {
      if (verbose) message(sprintf("  [%d/%d] task %d FAILED (left for retry)",
                                   i, nrow(pending), tk$task_id))
      next
    }
    row <- cbind(
      data.frame(task_id = tk$task_id, source = model, run_label = "code.php rubric temp0",
                 condition_num = tk$condition_num, text_role = tk$text_role,
                 stringsAsFactors = FALSE),
      res,
      data.frame(model = model, temperature = LLM_TEMPERATURE, stringsAsFactors = FALSE))
    results <- rbind(results, row)
    write.csv(results, out_path, row.names = FALSE)   # checkpoint after every task
    if (verbose) message(sprintf("  [%d/%d] task %d -> T%d D%d M%d (n=%d)",
                                 i, nrow(pending), tk$task_id,
                                 res$technique, res$dosage, res$mode, res$technique_count))
  }
  if (verbose) message(sprintf("[%s] Done. Wrote %s", model, out_path))
  invisible(results)
}

# The LLM panel: widely-used production model per lab, all temp 0. Gemini uses
# the fast Flash tier (consistent with Sonnet being Anthropic's workhorse); the
# slower 3.1 Pro run is archived out of data/ for a Pro-vs-Flash comparison.
PANEL_MODELS <- c(
  "anthropic/claude-sonnet-4.6",
  "openai/gpt-5.5",
  "google/gemini-3.5-flash",
  "x-ai/grok-4.3",
  "deepseek/deepseek-v3.2"
)

# Run when invoked directly: code whichever model is named on the command line
# (Rscript coding/llm_coder.R <model-slug>), else code the whole panel in turn.
if (sys.nframe() == 0) {
  a <- commandArgs(trailingOnly = TRUE)
  models <- if (length(a) >= 1) a else PANEL_MODELS
  for (m in models) code_all(m, verbose = TRUE)
}
