# ATLAS Pilot analysis

This folder contains the full analysis for the ATLAS Pilot study, a
three-condition between-subjects online experiment on how people describe the
self-care practices they use when stressed or anxious, and how much an AI coach
adds over a plain textual nudge or no help at all.

It is written to be read, run, and understood **in order**, by someone who did
not build the study. Every notebook opens with what it does and why, and each
result is followed by a plain-language "Read." note. You do not need prior
familiarity with the statistical methods; the modelling choices are explained
where they are used.

## The three conditions (context for every notebook)

- **C1 Baseline:** free text, no help.
- **C2 Nudge:** free text with a short verbal hint ("what you do, how much, in
  what way"). No AI.
- **C3 AI coach:** free text plus an LLM that scores each description 0-3 on three
  dimensions and offers hints; the participant edits and re-checks. Two snapshots
  are stored per C3 participant: `c3_first` (before any AI feedback) and
  `c3_final` (after the coaching loop).

The three measured dimensions are **Technique** (what you do), **Dosage** (how
much), and **Mode** (in what way), each coded 0-3 by crowd raters.

## Run order

Run the notebooks in number order. Notebook `00` is the only one that touches the
database; everything after it reads the CSVs that `00` writes, so the data source
is opened in exactly one place.

| # | File | Language | Reads | Produces |
|---|------|----------|-------|----------|
| 00 | `00_export.qmd` | R | the locked production DB (`data/final_prod/…`) | `participants.csv`, `self_reports.csv`, `codings.csv`, `coding_tasks.csv` |
| 01 | `01_participants.qmd` | R | `participants.csv`, `codings.csv` (+ optional Prolific exports) | sample descriptives for writers and raters |
| 02 | `02_self_reports.qmd` | R | `self_reports.csv` | distortion / fidelity / willingness / attention by condition |
| 03 | `03_coding.qmd` | R | `codings.csv` | **the main result**: specificity by condition (mixed ordinal models) |
| 04 | `04_embeddings.qmd` | Python | `coding_tasks.csv` | technique extraction + the clustered "technique landscape" figures |
| 05 | `05_context_outcome.qmd` | R | `self_reports.csv` | exploratory pass over the free-text context/outcome fields |
| 06 | `06_llm_coding.qmd` | R | the locked production DB (via `coding/llm_coder.R`) | a five-model temperature-0 LLM panel codes all 404 tasks; `data/llm_codings_*.csv` + LLM-vs-human agreement |
| 07 | `07_inter_rater_reliability.qmd` | R | `codings.csv`, `data/llm_codings_*.csv` | Krippendorff ordinal alpha per dimension: human panel, five-model LLM panel, pooled |
| 08 | `08_demographics_distress.qmd` | R | `participants.csv`, `self_reports.csv`, `codings.csv`, `prolific_writers.csv` | exploratory covariate checks: writer gender/age and PSS-4/GAD-2 vs specificity and self-reports |

`00`-`03`, `05`, and `06` are R/Quarto. `04` is a Python/Quarto notebook. They are
independent once `00` has run, so you can render any single one after `00`. Pages
`04` and `06` call the OpenRouter API (`04` is cached to `data/techniques.csv`, `06`
to `data/llm_codings.csv`), so both need the key and both are idempotent.

### How to run

Each file is a [Quarto](https://quarto.org) notebook. Positron and RStudio render
them from the editor ("Render" button). With the Quarto CLI you can also build the
**whole thing as one navigable website**, which is the intended way to read it
start to finish:

```bash
cd analysis
quarto render            # builds every page into _site/ as one linked site
quarto preview           # live-reloading local preview while editing
open _site/index.html    # the finished site (overview + sidebar in run order)
```

`_quarto.yml` defines the site and turns on `freeze`, so each page re-executes only
when its own source changes; the expensive API pages (04, 06) stay cached between
builds. To render a single page instead, `quarto render 03_coding.qmd`.

## What each notebook answers

- **00 Export.** Turns the one locked database into clean, analysis-ready CSVs.
  Nothing here is an analysis; it exists so the rest of the folder never reaches
  into the database. Re-running it overwrites the CSVs (idempotent).
- **01 Participants.** Who is in the two samples: the **writers** (people who
  described a practice) and the **raters** (crowd coders who scored the texts).
  In-app fields always show; country/age/gender appear if you add the Prolific
  exports (see "Optional demographics" below).
- **02 Self-reports.** The cost side of the trade-off. How much did each condition
  make people feel they left out, invented, or distorted detail, and how willing
  are they to contribute such descriptions unpaid.
- **03 Coding (the headline).** Does specificity on Technique, Dosage, and Mode
  differ across conditions? The dependent variable is an ordered 0-3 level and
  each text is scored by several raters, so the notebook uses a **cumulative-link
  mixed model** (ordinal logistic regression with a random intercept per text).
  The notebook explains, in line, why a plain ANOVA would be wrong here. It also
  reports the within-C3 before/after AI lift and crowd-rater reliability.
- **04 Embeddings.** The proposal-facing idea: use AI to read what people actually
  do. It extracts each person's primary technique in their own words (faithfully,
  nothing invented), embeds those short phrases, and clusters them into a "seed
  atlas" map of the technique landscape.
- **05 Context/outcome.** Deliberately left at the brainstorming stage. Light
  descriptives of the two free-text fields, then a documented menu of defensible
  next analyses. No modelling until a direction is chosen.
- **06 LLM coding.** A single LLM (`anthropic/claude-sonnet-4.6`, temperature 0)
  re-codes all 404 blinded tasks on the same 0-3 rubric the crowd uses, for a
  like-for-like LLM-coder-vs-human comparison across the whole dataset. The engine
  is `coding/llm_coder.R`; it is idempotent (one row per task in
  `data/llm_codings.csv`, codes only what is missing). This is broader than the
  runtime-coach check in `03`, which compares the live C3 coach to humans on the C3
  final texts only.

## Prerequisites

**R packages** (00-03, 05, 06): `DBI`, `RSQLite`, `ordinal` (mixed ordinal models in
03), `irr` (Krippendorff's alpha in 03), `ggplot2` (the bar charts in 02 and 03),
and `knitr` + `rmarkdown` (so Quarto can render the R notebooks). Base R covers the
rest. Install with
`install.packages(c("DBI","RSQLite","ordinal","irr","ggplot2","knitr","rmarkdown"))`.

**Python packages** (04 only): `numpy`, `pandas`, `matplotlib`, `openai`,
`sentence-transformers`, `scikit-learn`.

**OpenRouter API key** (pages 04 and 06): page 04 reads `OPENROUTER_API_KEY` from
the repo-root `.env` (gitignored); page 06 reads it from `OPENROUTER_API_KEY` in the
environment or from the gitignored file `coding/.openrouter_key`. Both cache their
results (`data/techniques.csv`, `data/llm_codings.csv`), so the API is called once;
delete the cache to recompute.

## What is where

```
analysis/
  00_export.qmd … 06_llm_coding.qmd        the pipeline, run in order
  index.qmd                                website landing page (overview + run order)
  _quarto.yml                              Quarto project: builds the pages into one site
  README.md                                this file
  data/
    final_prod/                            the locked production database (input to 00, 06)
    participants.csv self_reports.csv      written by 00 (regenerated each run)
    codings.csv coding_tasks.csv
    llm_c3_final.csv                       written by 00 (C3 runtime coach telemetry)
    techniques.csv                         written/cached by 04
    llm_codings.csv                        written/cached by 06 (LLM coder over 404 tasks)
    *_2026-05-29.csv, responses.csv,       legacy exports from the first-gen
      practice_extractions.csv, …          analysis; not read by 00-06
    _archive/                              older database snapshots
  figures/                                 rendered figures (PNG for docs, PDF for print)
  coding/
    llm_coder.R                            engine for 06 (prompt, API call, idempotent coder)
    taskflow_remaining_x3.csv              Taskflow URL CSV used to recruit crowd raters
  _superseded/                             the first-generation analysis; see its README
  _site/, _freeze/                         Quarto build output and cache (gitignored)
```

The `_superseded/` folder holds the original monolithic report
(`atlas_analysis.qmd`) and its `R/` helpers, kept for history. It is not part of
the current pipeline and should not be run. Its own README explains why it was
replaced and lists the few extras (Holm-corrected p-values, odds-ratio confidence
intervals, predicted-probability plots, an LLM-vs-human agreement table) that are
worth porting into `03_coding.qmd` if wanted.

## Optional demographics

The app database has no country/age/gender. To add them, drop the two Prolific
exports into `analysis/data/` as `prolific_writers.csv` and `prolific_raters.csv`;
`01_participants.qmd` merges them on the Prolific ID and reports them. Without
those files, 01 still runs and reports the in-app fields.

## Data and privacy

Participant data is **never committed** (see `.gitignore`: the CSVs, the
databases, and rendered HTML are all ignored). The released dataset is the seed
practice atlas, published separately on OSF/Zenodo with the paper. Treat
everything under `data/` as confidential raw data.
