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

**Key difference:** Conditions 1-2 have NO AI interaction during the study. Only Condition 3 involves live AI. **The measured specificity DV for ALL conditions is post-hoc human (Prolific) raters** (rubric pilot used to refine anchors and rater training; no numeric kappa threshold gates the move to full coding), not the LLM. In C3 the runtime LLM 0-3 scores drive the coach and are stored as telemetry; LLM-human agreement is reported post-hoc as a bounded comparison. Canonical-technique clustering for the atlas is done expert-style by Hosio + 1 coauthor.

### Domain

All participants describe a practice they use **specifically when feeling stressed or anxious**, not as part of their general routine. Two brief screeners are administered at intake for sample characterisation only (no eligibility gate, no moderator role): PSS-4 (Cohen & Williamson 1988), the four-item short form of the Perceived Stress Scale (Cohen, Kamarck & Mermelstein 1983), capturing past-month perceived stress (0-16); and GAD-2 (Kroenke et al. 2007), the two-item screener for generalised anxiety, capturing past-two-week anxiety symptoms (0-6). The pairing matches the prompt's stress + anxiety scope.

### Study Flow (as currently implemented in app)

1. Consent + eligibility
2. PSS-4 + GAD-2 intake (sample characterisation only, descriptive)
3. Practice description (condition-specific prompt)
4. [Condition 3 only] AI coach on a single screen: describe, Check (LLM scores T/D/M 0-3 with soft indicators and optional hints), edit and re-check up to 5 times, continue when it feels accurate
5. Fidelity check: C1/C2 see their raw text back; C3 sees the structured practice
6. Context + outcome + willingness to contribute (single combined step; interest and general feedback dropped 2026-05-28)
7. Debrief + completion code

### Key Measurement: Specificity per Dimension

Each dimension (T, D, M) is coded on a 0-3 specificity scale using **dimension-specific anchors** (Technique: absent/category/named/parameterised; Dosage: absent/vague/single-parameter/multi-parameter; Mode: absent/vague/specified/operationalised), grounded in TIDieR (Hoffmann 2014), the Michie ontologies (Michie 2013, Marques 2024), and the Mode of Delivery Ontology (Marques 2021). The measured DV is post-hoc human raters; in C3 the runtime LLM also scores 0-3 to drive the coach (telemetry). Sum specificity = T+D+M (0-9), reported as a descriptive composite only. The earlier "computability threshold" of ≥6 is dropped from the pilot's analysis.

### Dataset Contribution: Seed Practice Atlas (v0.1)

Released on Zenodo/OSF with the paper. Canonical-technique clustering done expert-style by Hosio + 1 coauthor using BCTO merge/split criteria (Marques et al., 2024). Not crowd-codable work.

## Implementation TODOs from the locked design

Most locked-design code changes have shipped (PSS-4 intake step, C1/C2 stress framing in prompts, single-screen C3 specificity meter with 0-3 telemetry, OpenRouter LLM, Railway deploy, researcher auto-fill preview mode). Remaining items:

- `paper/paper.tex`: `acmart` document class is still `manuscript`; switch to `sigconf` before submission. Venue declaration already names HCOMP 2026.

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
    refinement.php          -- C3 single-screen meter: describe + Check (LLM 0-3 scoring) + fixed per-dimension hints (NOT LLM-generated, to avoid technique-specific drift); re-check up to 5; RoundsTaken stored
    fidelity.php            -- Review + semantic_fidelity Likert + self_distortion Likert + optional open-text on omission/invention/distortion
    exploratory.php         -- Context + outcome + embedded attention-check IMC (1-7 Likert, pass = "Strongly disagree") + willingness-to-contribute-without-payment Likert (final data step before debrief; persists the full questionnaire row)
    questionnaire.php       -- Safety redirect to debrief (step merged into exploratory.php on 2026-05-28; interest and general_feedback items dropped)
    debrief.php             -- Completion code + Prolific redirect
  templates/                -- header.php, footer.php (Bootstrap layout)
  assets/style.css          -- Custom styles
  data/                     -- SQLite database file (GITIGNORED)

docs/                       -- GITIGNORED. Local-only research notes. Do not commit; "just code on GitHub."
  study_design_memo.md          -- Live study design (treat as draft; update to match the app, not the other way around)
  analysis_plan_v1.md           -- Live internal analysis plan (also a draft; mirror what the app actually captures)
  ideas_ontology_normalisation.md -- Research notes on practice normalisation (BCTO criteria, GO synonym taxonomy)
  pilot_idea.md.txt             -- SUPERSEDED original concept (kept for history)

paper/                      -- HCOMP/ACM paper (separate git repo, Overleaf-synced)
  paper.tex                 -- Paper source (currently declares CHI '27; needs HCOMP '26 update)
  references.bib            -- Bibliography
  context/                  -- Related-work .md notes
```

**Paper workflow:** Do not compile `paper/paper.tex` locally. The paper is Overleaf-synced and compiles there. After editing paper files, just commit and push the paper repo (no local `pdflatex`/`bibtex`).

Database tables: `participants`, `responses`, `practice_extractions`, `questionnaire`. Naming follows the current "practice" terminology end to end; the older "gene" symbols have been retired from the codebase.

## Tech Stack

- Backend: PHP 8+ (no framework), SQLite database
- Frontend: Bootstrap 5 via CDN
- Hosting: Railway
- LLM: OpenRouter (anthropic/claude-sonnet-4.6) for Condition 3 only (button-triggered specificity analysis)

## Deploy

**Railway is NOT auto-deployed from GitHub on this project.** The git push to `origin/master` only updates the GitHub mirror; it does not change what's running. To ship code, you must run the Railway CLI separately.

```bash
# From repo root, with the railway CLI logged in and the project linked.
railway up --detach
```

Verify the active deployment with `railway status`. The production URL is `https://atlas-web-production-4c95.up.railway.app/`. A 200 on `/` is the trivial health check; for any DB-schema change, the new column is added by `init_schema()` on the first request after deploy (idempotent `ALTER TABLE` helper in `app/db.php`).

The project is linked to workspace `Simo Hosio's Projects`, project `atlas-pilot`, environment `production`, service `atlas-web`, with a 4.9 GB volume mounted at `/data` for the SQLite database. The volume is the only persistence; deploys do not wipe it.

## App Feature Status

What's wired up in the live app, by step. Read this before asking whether something is implemented. Update this table when you ship or remove a feature.

| Step / Module | Wired in app | Notes |
|---|---|---|
| `consent.php` | ✅ | Self-declared eligibility (18+, has-practice, used-in-past-month) as one checkbox. Random condition assignment, completion code minted. No ID verification beyond Prolific PID from the query string. |
| `intake.php` | ✅ | PSS-4 (4 items, q2/q3 reverse-scored, sum stored) and GAD-2 (2 items, direct, sum stored). All raw item columns persisted on `participants`. |
| `input.php` | ✅ | Condition-specific prompt (C1 unscaffolded, C2 nudge with "what / how much / in what way"). 10-character minimum on description. No time floor, no plausibility item. |
| `refinement.php` (C3) | ✅ | Single-screen meter. Check button triggers LLM scoring T/D/M 0-3. Fixed per-dimension hint strings (NOT LLM-generated). At least 1 check required, cap 5. Per-check snapshot + RoundsTaken stored. LLM fallback to pre-generated extraction if API times out. |
| `fidelity.php` | ✅ | Review screen: C1/C2 see raw text, C3 sees structured practice. SemanticFidelity Likert 1-7, SelfDistortion Likert 1-7 (column `self_distortion`), optional open-text on omission/invention/distortion (column `fidelity_feedback`). |
| `exploratory.php` | ✅ | Context free text, outcome free text, embedded attention-check IMC (Likert 1-7, pass = "Strongly disagree" = 1), willingness-to-contribute-without-payment Likert 1-7. Persists the full questionnaire row and marks participant complete. |
| `questionnaire.php` | redirect-only | Merged into `exploratory.php` on 2026-05-28. Still exists as a safety redirect to debrief in case of stale links. |
| `debrief.php` | ✅ | Completion code shown; Prolific redirect link present for Prolific sessions, return-to-Prolific button shown for test sessions too. |
| Admin: stats & per-condition breakdown | ✅ | `/admin.php?key=<admin_key>`. |
| Admin: test links + `?fill=1` preview | ✅ | All 3 conditions; preview auto-fills via `synthetic_preview()` in `app/synthetic.php` with no DB writes. |
| Admin: participant detail (response chain, extraction trajectory, attention-check pass/fail) | ✅ | |
| Admin: CSV export, SQLite download | ✅ | |
| Bot detection | external | Prolific native checks at recruitment + the IMC item above. App does not run independent bot detection. |
| 90s minimum response time on description | ❌ | Considered, not implemented. Out-of-scope for the launched pilot. |
| Practice-plausibility follow-up item | ❌ | Considered, not implemented. |
| Self-reported demand-characteristic item | ❌ | Considered, not implemented. |

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
