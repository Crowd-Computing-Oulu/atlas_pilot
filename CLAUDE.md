# CLAUDE.md

This file provides guidance to Claude Code when working with code in this repository.

## EXTREMELY IMPORTANT: Scientific Rigour

Never produce hand-wavy, theoretically indefensible claims. This is a scientific project targeting a HCOMP 2026 full paper, with an ERC Consolidator grant proposal as broader context. Every design choice, every mechanism, every causal claim must be theoretically grounded and defensible. If something cannot be justified rigorously, say so. If you are unsure whether a claim holds, flag it as uncertain rather than presenting it as fact. Simo is a professor and CHI subcommittee chair; do not waste his time with nonsense.

## Project Overview

ATLAS Pilot is a three-condition between-subjects online experiment (target N≥300) that takes a first empirical step toward measuring how everyday self-care practices can be reliably elicited and structured in a human-AI partnership. The study targets **HCOMP 2026 (full paper, archival)** and produces a v0.1 seed practice atlas for stress and anxiety coping.

The pilot is independent of the ATLAS proposal's planned cross-cultural N≈1000 study. The proposal lives at `~/Documents/Academic/Proposals/ATLAS` (see `CLAUDE.local.md` for paths and venue context).

### Locked design

The locked design is in `docs/study_design_memo.md`. The internal date-stamped analysis plan is in `docs/analysis_plan_v1.md`. **No public OSF pre-registration.**

### Terminology

- **Primitive:** a single typed value on one dimension. Three primitive types: Technique (T), Dosage (D), Mode (M).
- **Practice:** a triple ⟨T, D, M⟩.
- **Practice report:** practice + context + outcome (raw participant submission).

Pilot artefacts use these terms consistently. Earlier drafts used "behavioural gene" for what is now "practice"; that terminology has been retired from paper-facing artefacts.

### Conditions

- **Condition 1 (Pure baseline):** Free text, no hints, no AI. Tests at what specificity level people naturally encode T/D/M.
- **Condition 2 (Textual nudge):** Free text with verbal hints toward "what you do, how much, in what way." No AI. Tests whether minimal prompting increases specificity.
- **Condition 3 (AI coach):** Single screen. Free text (same prompt as C1) with a "Check my description" button that runs one LLM analysis scoring each dimension 0-3 against the rubric and showing soft per-dimension indicators plus optional hints. The participant edits their own narrative and re-checks (generous cap of 5), continuing whenever it feels accurate (no forced threshold; at least one check required so the AI treatment is always delivered). The LLM scores and hints but never rewrites the text. **RoundsTaken = number of re-checks after the first (open count)** is a primary variable of interest.

**Key difference:** Conditions 1-2 have NO AI interaction during the study. Only Condition 3 involves live AI. **The measured specificity DV for ALL conditions is post-hoc human (Prolific) raters** (rubric pilot to kappa ≥ 0.6), not the LLM. In C3 the runtime LLM 0-3 scores drive the coach and are stored as telemetry; LLM-human agreement is reported post-hoc (a bounded limitation, not the result). Canonical-technique clustering for the atlas is done expert-style by Hosio + 1 coauthor.

### Domain

All participants describe a practice they use **specifically when feeling stressed or anxious**, not as part of their general routine. PSS-4, the four-item brief form (Cohen & Williamson 1988) of the Perceived Stress Scale (Cohen, Kamarck & Mermelstein 1983), administered at intake for sample characterisation only.

### Study Flow (as currently implemented in app)

1. Consent + eligibility
2. PSS-4 intake (sample characterisation only, descriptive)
3. Practice description (condition-specific prompt)
4. [Condition 3 only] AI coach on a single screen: describe, Check (LLM scores T/D/M 0-3 with soft indicators and optional hints), edit and re-check up to 5 times, continue when it feels accurate
5. Fidelity check: C1/C2 see their raw text back; C3 sees the structured practice
6. Context + outcome (exploratory practice-report fields)
7. Questionnaire (willingness, interest, optional open feedback)
8. Debrief + completion code

### Key Measurement: Specificity per Dimension

Each dimension (T, D, M) is coded on a 0-3 specificity scale using **dimension-specific anchors** (Technique: absent/category/named/parameterised; Dosage: absent/vague/single-parameter/multi-parameter; Mode: absent/vague/specified/operationalised), grounded in TIDieR (Hoffmann 2014), the Michie ontologies (Michie 2013, Marques 2024), and the Mode of Delivery Ontology (Marques 2021). The measured DV is post-hoc human raters; in C3 the runtime LLM also scores 0-3 to drive the coach (telemetry). Sum specificity = T+D+M (0-9), reported as a descriptive composite only. The earlier "computability threshold" of ≥6 is dropped from the pilot's analysis.

### Dataset Contribution: Seed Practice Atlas (v0.1)

Released on Zenodo/OSF with the paper. Canonical-technique clustering done expert-style by Hosio + 1 coauthor using BCTO merge/split criteria (Marques et al., 2024). Not crowd-codable work.

## Implementation TODOs from the locked design

Most locked-design code changes have shipped (PSS-4 intake step, C1/C2 stress framing in prompts, single-screen C3 specificity meter with 0-3 telemetry, OpenRouter LLM, Railway deploy, researcher auto-fill preview mode). Remaining items:

- `paper/paper.tex`: `acmart` document class is still `manuscript`; switch to `sigconf` before submission. Venue declaration already names HCOMP 2026.
- (Optional cleanup) Internal symbols retain legacy "gene" terminology: function names (`extract_gene`, `refine_gene`), session/array variables (`$gene_json`, `$_SESSION['current_practice']` partially renamed), CSS classes (`.gene-card`, `.gene-label`, `.gene-value`, `.gene-missing`), and the `gene_extractions` DB table. None are user-facing.

## Key Files and Folders

```
app/                        -- PHP web application
  index.php                 -- Entry point, step router, session management
  admin.php                 -- Admin dashboard (stats, participant details, exports, DB download)
  config.php                -- Local config: DB path, API key, admin password (GITIGNORED)
  config.example.php        -- Template for config
  db.php                    -- SQLite connection + schema init
  llm.php                   -- OpenRouter (OpenAI-compatible) wrapper; analyse_practice scores T/D/M 0-3
  steps/                    -- One PHP file per study step
    consent.php             -- Consent + eligibility + condition assignment
    intake.php              -- PSS-4 (4 items, q2/q3 reverse-scored, sum stored)
    input.php               -- Practice description (condition-specific prompt)
    refinement.php          -- C3 single-screen meter: describe + Check (LLM 0-3 scoring) + hints; re-check up to 5; RoundsTaken stored
    fidelity.php            -- Review + semantic_fidelity + forced_fit Likerts + optional feedback
    exploratory.php         -- Context + outcome free-text fields
    questionnaire.php       -- Willingness + interest Likerts + optional general feedback
    debrief.php             -- Completion code + Prolific redirect
  templates/                -- header.php, footer.php (Bootstrap layout)
  assets/style.css          -- Custom styles
  data/                     -- SQLite database file (GITIGNORED)

docs/
  study_design_memo.md          -- Locked study design (source of truth)
  analysis_plan_v1.md           -- Locked, date-stamped internal analysis plan
  ideas_ontology_normalisation.md -- Research notes on practice normalisation (BCTO criteria, GO synonym taxonomy)
  pilot_idea.md.txt             -- SUPERSEDED original concept (kept for history)

paper/                      -- HCOMP/ACM paper (separate git repo, Overleaf-synced)
  paper.tex                 -- Paper source (currently declares CHI '27; needs HCOMP '26 update)
  references.bib            -- Bibliography
  context/                  -- Related-work .md notes
```

**Paper workflow:** Do not compile `paper/paper.tex` locally. The paper is Overleaf-synced and compiles there. After editing paper files, just commit and push the paper repo (no local `pdflatex`/`bibtex`).

Note on internal naming: the database table `gene_extractions` and some PHP variables / CSS classes (`$gene`, `.gene-card`) retain the older terminology. They are not user-facing and have been left alone to avoid schema migrations during active development. Renaming is optional cleanup.

## Tech Stack

- Backend: PHP 8+ (no framework), SQLite database
- Frontend: Bootstrap 5 via CDN
- Hosting: Railway
- LLM: OpenRouter (anthropic/claude-sonnet-4.6) for Condition 3 only (button-triggered specificity analysis)

## Admin Dashboard

Access: `/admin.php?key=<admin_key>`

- Overview stats, per-condition breakdown
- Test links and auto-filled preview links for all 3 conditions (no DB writes)
- Participant table with delete button (removes all associated data)
- Detail view per participant (full response chain, practice extraction trajectory)
- CSV exports
- Raw SQLite DB download

## Study Parameters

- Target: ≥100 per condition (≥300 total). Round-number total locked after a small pilot launch confirms recruitment rate.
- Recruited via Prolific + open web (source tracked via PID prefix).
- Domain: practices used specifically when feeling stressed or anxious.
- Duration: ~10 minutes per participant.
- Compensation: €3-4.
- Pre-registration: NONE (no public OSF pre-reg). Internal analysis plan in `docs/analysis_plan_v1.md`.
