# Superseded analysis (kept for reference, do not run)

These files are the **first-generation** ATLAS analysis, written 2026-05-29,
before the production data and the human coding existed. They are kept for
history and are **not** part of the current pipeline. The live analysis is the
numbered `00`–`05` notebooks one level up; start from `../README.md`.

## What is here

- `atlas_analysis.qmd` — a single monolithic confirmatory report. It was written
  to the locked analysis plan while the study was still running, so it expects a
  `data/human_specificity.csv` (wide: `participant_id, rater_id, technique_level,
  dosage_level, mode_level`) that was **never produced**. The actual human coding
  arrived in long form via the `codings` table (`data/codings.csv`), which the
  new `03_coding.qmd` reads directly. Most sections in this file are therefore
  stubbed out behind `if (!has_human)` guards.
- `atlas_analysis.html` — the rendered output of the above (mostly skipped cells).
- `R/load_data.R`, `R/helpers.R` — the data layer and stats helpers used **only**
  by `atlas_analysis.qmd`. The numbered notebooks do not source them.

## Why it was replaced

The export model changed. The new `00_export.qmd` reads the locked production
database once and writes tidy CSVs; the old file read a different, earlier set of
admin-dashboard CSV exports. More importantly, the real data is **multi-rater**
(several crowd coders per text), so the correct model is a cumulative-link
**mixed** model with a random intercept per task (`ordinal::clmm`, used in
`03_coding.qmd`). The old file used a plain `clm`, which ignores the
within-task rater correlation and would overstate precision.

## Worth porting (the old file did these; the new `03` does not yet)

If you want to fold any of this into `03_coding.qmd`, it is here ready to lift:

- **Holm correction** across the full family of condition contrasts.
- **Wald 95% confidence intervals** on the odds ratios (not just point estimates).
- **Predicted category-probability plots** per condition and dimension.
- An explicit **LLM-vs-human agreement** table (quadratic-weighted Cohen's kappa)
  on the same C3 texts.

Everything else in the old file is either already covered better in `03`
(Krippendorff's alpha, within-1 agreement) or no longer matches the design.
