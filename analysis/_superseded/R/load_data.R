# Data layer for the ATLAS analysis.
# Reads the four CSV exports from the admin dashboard and builds the tidy
# frames the report uses. Put the exports in analysis/data/ named exactly:
#   participants.csv  responses.csv  practice_extractions.csv  questionnaire.csv
# (all gitignored).

suppressPackageStartupMessages({
  library(dplyr)
  library(tidyr)
})

# Condition labels used everywhere. C1 is the reference level for all models.
CONDITION_LEVELS <- c("C1 Baseline", "C2 Nudge", "C3 AI coach")

label_condition <- function(num) {
  factor(CONDITION_LEVELS[num], levels = CONDITION_LEVELS)
}

# Coerce named columns to numeric in place (export CSVs can carry blanks).
.numify <- function(df, cols) {
  for (c in intersect(cols, names(df))) df[[c]] <- suppressWarnings(as.numeric(df[[c]]))
  df
}

# Load the four CSV exports and return a named list of tibbles.
load_atlas <- function(data_dir = "data") {
  read_one <- function(name) {
    f <- file.path(data_dir, paste0(name, ".csv"))
    if (!file.exists(f)) {
      stop("Missing '", f, "'. Export the four CSVs into analysis/data/ ",
           "named participants.csv, responses.csv, practice_extractions.csv, ",
           "questionnaire.csv (see analysis/README.md).")
    }
    # readr handles RFC-4180 quoted fields with embedded newlines/commas
    # (raw_llm_response is multi-line JSON); base read.csv chokes on those.
    if (requireNamespace("readr", quietly = TRUE)) {
      readr::read_csv(f, show_col_types = FALSE, progress = FALSE)
    } else {
      as_tibble(utils::read.csv(f, stringsAsFactors = FALSE, na.strings = c("", "NA")))
    }
  }

  participants <- read_one("participants") |>
    .numify(c("id", "condition_num", "pss4_q1", "pss4_q2", "pss4_q3", "pss4_q4",
              "pss4_sum", "rounds_taken", "gad2_q1", "gad2_q2", "gad2_sum"))
  responses <- read_one("responses")
  extractions <- read_one("practice_extractions") |>
    .numify(c("participant_id", "round", "technique_level", "dosage_level", "mode_level"))
  questionnaire <- read_one("questionnaire") |>
    .numify(c("participant_id", "semantic_fidelity", "self_distortion",
              "willingness", "attention_check"))

  list(participants = participants, responses = responses,
       extractions = extractions, questionnaire = questionnaire)
}

# Participant-level analysis frame: one row per participant.
build_participants <- function(raw) {
  p <- raw$participants |>
    mutate(
      condition = label_condition(condition_num),
      completed = !is.na(completed_at),
      # Recompute PSS-4 from items as a defensive check (q2, q3 reverse-scored, 0-16).
      # Positively framed items q2 and q3 are reversed; this must match the app.
      pss4_recomputed = pss4_q1 + (4 - pss4_q2) + (4 - pss4_q3) + pss4_q4,
      gad2_recomputed = gad2_q1 + gad2_q2
    )

  # Attach the final questionnaire row (alignment + attention check).
  q <- raw$questionnaire |>
    transmute(
      participant_id,
      semantic_fidelity, self_distortion, willingness,
      attention_pass = attention_check == 1L,
      context_len = nchar(coalesce(context_text, "")),
      outcome_len = nchar(coalesce(outcome_text, ""))
    )

  # Initial free-text description length, for the dataset glimpse.
  desc <- raw$responses |>
    filter(step == "initial_description") |>
    group_by(participant_id) |>
    summarise(description_len = max(nchar(coalesce(response_text, "")), na.rm = TRUE),
              .groups = "drop")

  p |>
    left_join(q, by = c("id" = "participant_id")) |>
    left_join(desc, by = c("id" = "participant_id")) |>
    rename(participant_id = id)
}

# LLM specificity (C3 only): the FINAL extraction per participant, in long form.
# This is the runtime coach telemetry, used as the secondary/illustrative lens.
# It is NOT the confirmatory DV (that is human-rater coding, see read_human_specificity).
build_llm_specificity <- function(raw, participants) {
  if (nrow(raw$extractions) == 0) {
    return(tibble(participant_id = integer(), dimension = factor(),
                  level = ordered(integer(), levels = 0:3), condition = factor()))
  }
  final <- raw$extractions |>
    group_by(participant_id) |>
    filter(round == max(round)) |>
    ungroup() |>
    transmute(participant_id,
              Technique = technique_level,
              Dosage = dosage_level,
              Mode = mode_level)
  final |>
    pivot_longer(c(Technique, Dosage, Mode),
                 names_to = "dimension", values_to = "level") |>
    mutate(dimension = factor(dimension, levels = c("Technique", "Dosage", "Mode")),
           level = ordered(level, levels = 0:3)) |>
    left_join(select(participants, participant_id, condition), by = "participant_id")
}

# Human-rater specificity (the confirmatory DV). Not collected until the rubric
# pilot completes, so this returns NULL when the file is absent. Expected schema
# (wide, one row per participant x rater) at analysis/data/human_specificity.csv:
#   participant_id, rater_id, technique_level, dosage_level, mode_level   (each 0-3)
# Multiple raters per participant are aggregated to the ordinal median per
# dimension for the primary models; the raw rater rows feed the IRR analysis.
read_human_specificity <- function(participants, path = "data/human_specificity.csv") {
  if (!file.exists(path)) return(NULL)
  raw <- utils::read.csv(path, stringsAsFactors = FALSE) |> as_tibble()
  need <- c("participant_id", "rater_id", "technique_level", "dosage_level", "mode_level")
  miss <- setdiff(need, names(raw))
  if (length(miss)) stop("human_specificity.csv missing columns: ", paste(miss, collapse = ", "))

  long_raters <- raw |>
    pivot_longer(c(technique_level, dosage_level, mode_level),
                 names_to = "dimension", values_to = "level") |>
    mutate(dimension = recode(dimension,
                              technique_level = "Technique",
                              dosage_level = "Dosage",
                              mode_level = "Mode"),
           dimension = factor(dimension, levels = c("Technique", "Dosage", "Mode")))

  consensus <- long_raters |>
    group_by(participant_id, dimension) |>
    summarise(level = stats::median(level), .groups = "drop") |>
    mutate(level = ordered(round(level), levels = 0:3)) |>
    left_join(select(participants, participant_id, condition), by = "participant_id")

  list(raters = long_raters, consensus = consensus)
}
