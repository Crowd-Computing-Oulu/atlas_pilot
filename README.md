# ATLAS Pilot

Empirical pilot study targeting **HCOMP 2026** (full paper, archival). Produces a v0.1 seed practice atlas for stress and anxiety coping. This pilot feeds the broader ATLAS programme on everyday self-care; it is methodologically self-contained.

## Why

Self-care is universal but scientifically fragmented. Millions of people exercise, meditate, journal, and breathe intentionally, yet there is no shared computational language to describe, compare, or combine these practices across people and contexts. This pilot tests how well people can describe their own self-care practices in a structured way, with and without AI assistance.

## Terminology

- **Primitive:** a single typed value on one dimension. Three primitive types: Technique (T), Dosage (D), Mode (M).
- **Practice:** a triple ⟨T, D, M⟩.
- **Practice report:** practice + context + outcome (what a participant submits).

A morning jog is a practice: ⟨T: Running, D: 20min 3x/week, M: Solo outdoors⟩. A guided breathing exercise is another: ⟨T: Box Breathing 4-4-4-4, D: 5min daily, M: Solo with timer app⟩. The same compositional structure captures both.

## This Pilot

Three-condition between-subjects online experiment (target N≥300) on Prolific. All participants describe a practice they use **specifically when feeling stressed or anxious**.

| Condition | What participants do | What it tests |
|-----------|---------------------|---------------|
| **1. Pure Baseline** (n≥100) | Describe a practice in free text, no hints | Natural per-dimension specificity (T, D, M) |
| **2. Textual Nudge** (n≥100) | Same free text + a clause-level prompt hinting at "what you do, how much, in what way" | Whether a minimal verbal nudge moves people up the specificity scale |
| **3. AI Coach** (n≥100) | Free text on a single screen with an on-demand "Check my description" button; an LLM scores each dimension 0-3 and surfaces three soft indicators with fixed per-dimension hints. Participants self-edit and re-check, generous cap of 5 (at least one check required, no forced threshold). | Refinement trajectory; how often free text is already complete at first check (RoundsTaken open count is itself a DV) |

PSS-4 (Cohen & Williamson 1988) and GAD-2 (Kroenke et al. 2007) administered at intake for sample characterisation (no eligibility gate, no moderator role). The pairing matches the prompt's stress + anxiety scope.

## Key Measurement

Per-dimension specificity coded on a 0-3 scale (absent → category → named → parameterised), grounded in TIDieR (Hoffmann 2014) and the Michie ontologies. Coding is done post-hoc by Prolific raters with kappa reporting (rubric pilot first to achieve kappa ≥ 0.6 per dimension). Canonical-technique clustering for the atlas is expert-coded by Hosio + 1 coauthor using BCTO merge/split criteria (Marques 2024).

## Outputs

1. **HCOMP 2026 paper** framed around three pillars: human-AI complementarity (lead, via the C2 vs C3 contrast), alignment / fidelity (semantic-fidelity and forced-fit measures across rounds), and the dataset contribution.
2. **Seed practice atlas v0.1** released on Zenodo / OSF, CC-BY: canonical practices for stress and anxiety coping with primitive-level frequencies, per-dimension specificity profiles, and self-reported context-outcome triples.

## Tech Stack

- PHP + SQLite
- Bootstrap frontend
- OpenRouter (OpenAI-compatible API; frozen on `anthropic/claude-sonnet-4.6`) for the Condition 3 button-triggered scoring
- Hosted on Railway

## Documents

- `docs/study_design_memo.md` — Locked study design (source of truth)
- `docs/analysis_plan_v1.md` — Locked internal analysis plan
- `docs/ideas_ontology_normalisation.md` — Research notes on practice normalisation
- `docs/pilot_idea.md.txt` — SUPERSEDED original concept (kept for history)

The HCOMP paper itself is in `paper/`, a separate Overleaf-synced git repo.

## Team

[Crowd Computing Group](https://www.oulu.fi/en/university/faculties-and-units/faculty-information-technology-and-electrical-engineering/crowd-computing), University of Oulu.
