# ATLAS Pilot: Study Design Memo

**Date:** 2026-04-26 (locked design)
**Author:** Simo Hosio
**Status:** LOCKED. Design committed. Implementation tweaks may follow but the study design itself is fixed. Amended 2026-05-28: added GAD-2 alongside PSS-4 at intake (anxiety half of prompt scope); dropped the kappa >= 0.6 numeric threshold from the rubric pilot in favour of a qualitative refinement target.

---

## Goal

This pilot is a self-contained empirical study targeting **HCOMP 2026 (full paper, archival)**. It measures how everyday self-care practices can be reliably elicited and structured in a human-AI partnership, and it produces a v0.1 seed practice atlas for stress and anxiety coping.

The study addresses three questions:

1. **Natural specificity:** at what specificity level (per dimension: Technique, Dosage, Mode) do people encode their self-care practices when describing them in their own words, with no scaffolding?
2. **Scaffolding effects:** does a minimal verbal nudge, or AI-assisted dialogue, change that specificity, and which dimensions respond most?
3. **Refinement trajectory:** within an AI-coach condition, how often do participants judge their free-text description as already complete, and how does specificity evolve when they do engage in refinement?

This is a concept-formation and data-collection study. It is independent of the ATLAS proposal's planned cross-cultural N≈1000 study, which is a separate later phase.

## Terminology

- **Primitive:** a single typed value on one dimension. Three primitive types: Technique (T), Dosage (D), Mode (M).
- **Practice:** a triple ⟨T, D, M⟩, one primitive of each type.
- **Practice report:** the raw participant submission. Practice plus context plus outcome.

This terminology is used consistently in pilot artefacts and is harmonised with the ATLAS proposal.

---

## Domain and Sample

### Practice scope

All participants describe a practice they use **specifically when feeling stressed or anxious**, not as part of their general wellbeing routine. The "specifically when" wording appears in the prompt itself. Rationale:

- Tightens the dataset so practices are more directly comparable at the atlas level.
- Excludes generic-wellbeing responses ("I just go to the gym") that are not stress-coping per se.
- Aligned with the HCOMP narrative: stress and anxiety coping carries clear wellbeing stakes for the alignment / fidelity story.

### Sample

Recruited via Prolific (also accessible via open web link, source tracked via PID prefix). No screening on mental health diagnosis or symptom severity.

Two brief screeners administered at intake for sample characterisation. Used descriptively; no eligibility gate, no role as moderator in analysis. Updated 2026-05-28 to add GAD-2 alongside PSS-4 so the screener pair matches the prompt's stress + anxiety scope.

**PSS-4** (Perceived Stress Scale, 4-item; Cohen & Williamson 1988): past-month perceived stress, scored 0-16 (q2 and q3 reverse-scored).

**GAD-2** (Kroenke et al. 2007): past-two-week anxiety symptoms, scored 0-6, derived from GAD-7. Standard ultra-brief anxiety screener.

**Why both, why brief:**
- Combined ~45 seconds. Minimal participant burden.
- Match the prompt scope: PSS-4 alone would leave the anxiety half of the prompt uncharacterised, which a reviewer would catch.
- Both are normal-population screeners and are not clinical diagnostic tools (sidesteps the ethical question of mental-health screening on Prolific).
- Both are free, no licensing.
- PSS-4 psychometric validation in a representative population sample supports the short form for survey administration (Schmalbach et al. 2025); the same paper uses GAD-2 as a convergent validity check on PSS-4, so the pairing is precedented.
- Limitation: PSS-4 has lower internal consistency than PSS-10 (alpha ~ 0.6 to 0.72); GAD-2 is a screener rather than a diagnostic instrument. Acceptable for sample characterisation only.

### Sample size

Round-number target ≥100 per condition (≥300 total). Final N decided after a small pilot launch confirms recruitment rate. Power analysis deferred to submission, reported retrospectively.

---

## Specificity Coding Scheme

Grounded in TIDieR (Hoffmann et al., 2014), the BCT taxonomy (Michie et al., 2013), and the Mode of Delivery Ontology (Marques et al., 2021). Dependent variable is **specificity per dimension**, ordinal 0-3.

We do **not** use a "computability threshold" in this paper. The earlier 6/9 threshold belonged to the proposal narrative. For the pilot, we report per-dimension specificity directly, with the simple sum T+D+M as a descriptive composite only.

### Technique Specificity (What you do)

| Level | Label | Description | Example |
|-------|-------|-------------|---------|
| 0 | Absent | No technique mentioned | "I just try to feel better" |
| 1 | Category | Broad category only | "relaxation", "exercise" |
| 2 | Named | Specific named practice | "box breathing", "running" |
| 3 | Parameterised | Practice with defining parameters | "4-4-4-4 box breathing", "interval running at 70% HR" |

### Dosage Specificity (How much)

| Level | Label | Description | Example |
|-------|-------|-------------|---------|
| 0 | Absent | No dosage information | (none) |
| 1 | Vague | Non-quantified | "sometimes", "when I need it", "a bit" |
| 2 | Single parameter | One of duration, frequency, or intensity | "20 minutes" or "3x/week" |
| 3 | Multi-parameter | Two or more | "20 min, 3x/week" or "5 min every morning" |

### Mode Specificity (In what form)

| Level | Label | Description | Example |
|-------|-------|-------------|---------|
| 0 | Absent | No mode information | (none) |
| 1 | Vague | Minimal context | "by myself", "at home" |
| 2 | Specified | Clear mode with one qualifier | "solo outdoors", "with a group in class" |
| 3 | Operationalised | Mode plus delivery mechanism or setting detail | "solo outdoors using Headspace app, in park near work" |

---

## Study Design: Three Conditions

Between-subjects, random assignment. ~10 minutes per participant.

### Condition 1: Pure Baseline (n≥100)

**Prompt:** "Think of something you do **specifically when you are feeling stressed or anxious** to help yourself feel better. Describe it in your own words. Tell us whatever feels important about what you do."

No scaffolding, no hints, no AI. Large free-text box.

Tests: at what specificity level do people naturally encode T, D, and M without prompting?

### Condition 2: Textual Nudge (n≥100)

**Prompt:** "Think of something you do **specifically when you are feeling stressed or anxious** to help yourself feel better. Try to describe: what exactly you do, how much or how often, and in what way or setting."

Same free-text box. Only difference is three clause-level hints in the prompt. No labels, no cards, no AI.

Tests: does a minimal verbal prompt push people up the specificity scale, and on which dimensions?

### Condition 3: AI-Assisted Iterative Refinement (n≥100)

Single screen (description entry and refinement merged). Same unscaffolded prompt as C1.

1. The participant writes a free-text description and clicks **Check my description** (button-triggered, not real-time).
2. An LLM scores each dimension 0-3 against the dimension-specific rubric and renders three soft per-dimension indicators (qualitative label, calm fill, no numeric score, neutral state for "not mentioned"). When a dimension scores below the top level, the interface shows a fixed, per-dimension hint string that mirrors the C2 nudge clauses verbatim: T = "Try naming what exactly you do.", D = "Try adding how much or how often.", M = "Try adding in what way or setting." The LLM also emits a free-text hint, which is captured in raw_llm_response as telemetry but never shown to the participant, so every C3 participant who sees a hint sees the same words regardless of their practice. This guard prevents technique-specific LLM phrasing from confounding the cross-condition comparison. The LLM only scores; it never rewrites the participant's text.
3. The participant edits their own narrative and re-checks. A generous cap of 5 checks applies. At least one check is required, so every C3 participant receives the AI treatment and the round-0 baseline text is captured.
4. Persistent reassurance states they may stop whenever the description feels accurate, with no right answer and no payment dependence on the indicators. The participant continues whenever they choose.

**Variable of interest: RoundsTaken (open count) = number of re-checks after the first.** What fraction of descriptions participants judge complete at first check, and how specificity evolves across checks.

**Measurement separation.** The runtime LLM 0-3 scores drive only the coach and are stored as telemetry. The measured specificity DV (all conditions) is post-hoc human (Prolific) raters; LLM-human agreement is reported as a bounded comparison. The coaching instrument (LLM) is independent of the measuring instrument (humans), so there is no circularity.

Tests: how does AI-assisted refinement change per-dimension specificity, isolated from textual-scaffolding effects (via the C2 control)?

---

## Study Flow (as implemented in app)

1. Consent + eligibility + PSS-4 + GAD-2
2. Practice description (C1/C2: condition-specific prompt; C3 merges description and refinement on one screen)
3. [C3 only] On the same screen: Check (LLM 0-3 scoring with soft indicators and hints), edit and re-check up to 5 times, continue when accurate
4. Fidelity check: C1 and C2 see their raw text back; C3 sees the structured practice
5. Context + outcome (exploratory practice-report fields)
6. Questionnaire (willingness, interest, feedback)
7. Debrief + completion code

---

## Measures

### DVs

All measures listed together; no formal primary/secondary distinction is used in this pilot.

| Measure | Type | Applied to | Role |
|---------|------|-----------|------|
| Per-dimension specificity (T, D, M each 0-3) | Coded by Prolific raters, blind | All conditions, on final text (C1/C2) and per-check snapshots (C3) | Central specificity outcome |
| Sum specificity (T+D+M, 0-9) | Derived | All conditions | Descriptive composite only, no threshold |
| RoundsTaken (open count) | Telemetry | C3 | What fraction judge complete at first check vs engage further |
| Per-check specificity gain | Telemetry (runtime LLM 0-3) + human coding | C3 | Refinement trajectory |
| Semantic fidelity (Likert 1-7) | Self-report | All conditions | Output-level alignment of displayed representation |
| Self-reported distortion (Likert 1-7) | Self-report | All conditions | Process-level honesty: did the participant leave out, invent, or distort? Of particular interest in C3 (AI coach may pressure fabrication). Stored in legacy column `forced_fit`. |
| Distortion open-text ("if you left out, invented, or distorted, explain") | Free text | All conditions | Directed content analysis (Hsieh & Shannon 2005) into pre-specified categories: omission, invention, distortion. Stored in legacy column `fidelity_feedback`. |
| Time per check | Telemetry | C3 | Effort trajectory |
| Runtime LLM 0-3 scores per check | Telemetry | C3 | Coaching signal; LLM-human agreement reported as a bounded comparison |
| Willingness to contribute (Likert 1-7) | Self-report | All conditions | Atlas viability signal |
| Interest (Likert 1-7) | Self-report | All conditions | Study experience |
| Context and outcome triples | Open text | All conditions | Atlas enrichment |
| PSS-4 (0-16) | Self-report at intake | All conditions | Sample characterisation only |
| GAD-2 (0-6) | Self-report at intake | All conditions | Sample characterisation only |

---

## Coding Protocol

### Specificity coding

Two-stage:

1. **Rubric pilot.** Take ~50 sample descriptions (drawn from a small pre-launch dry run, n≈10-15 across conditions). Recruit ~5 Prolific raters per item. Each rater applies the rubric, with worked examples shown inline. Compute pairwise kappa per dimension as a diagnostic. The pilot is used to refine anchors, sharpen worked examples, and finalise rater training; no fixed kappa threshold gates the move to full coding (numeric thresholds at this scale would be brittle and uninformative). The decision to proceed is qualitative (anchors stable, raters report low ambiguity), with the pilot kappa reported alongside the full-coding kappa in the paper.
2. **Full coding.** ≥3 Prolific raters per response, blind to condition. Final per-dimension specificity = modal label across raters. Cases with no majority resolved by expert (Hosio). Inter-rater kappa per dimension reported on the full set.

Rubric is operationalised as a decision tree with worked examples per level. Drafted before the rater pilot; locked when the pilot achieves the kappa threshold.

### Canonical-technique clustering (atlas)

Expert-coded by Hosio plus one coauthor, applying BCTO merge/split criteria (Marques et al., 2024): same mechanism of action → same canonical technique. Independent first pass, agreement reported as kappa, disagreements adjudicated jointly. Not crowd-codable work.

### Internal analysis plan

No public OSF pre-registration. Internal date-stamped analysis plan in `docs/analysis_plan_v1.md`, written before any data inspection. Anything not specified there is flagged as exploratory in the paper.

---

## Validity Controls

- Attention checks: 2-3 embedded items plus a minimum response time (90s for the description task).
- Practice-plausibility follow-up: requires consistent answer ("How long have you been doing this practice?").
- Demand-characteristic check: "Did you feel the study was trying to get you to respond in a particular way?"
- LLM fallback (C3): pre-generated extractions used if the API times out.
- Bot detection: Prolific native checks plus timing filters.
- PSS-4 / GAD-2 attention: standard quality checks (response variance, time).

---

## Analysis Plan (summary)

Detailed plan in `docs/analysis_plan_v1.md`. High-level:

- **C1 vs C2.** Three ordinal logistic regressions (one per dimension). Bonferroni-corrected.
- **C1 vs C3-final.** Three ordinal logistic regressions, same structure. Bonferroni-corrected.
- **C3 within-subjects refinement.** Cumulative-link mixed-effects model on per-dimension specificity by check, fit on all C3 participants (RoundsTaken=0 contribute their round-0 datum, RoundsTaken≥1 contribute the slope). Random intercept per participant.
- **RoundsTaken distribution.** Descriptive (open count: proportion at 0, 1, 2, ... re-checks).
- **Dimension asymmetry.** Compare effect sizes across T vs D vs M within each contrast.
- **Atlas analyses.** Descriptive (canonical cluster count, frequency distribution, per-cluster specificity, dosage and mode value variation per cluster).
- **Power-law fit on technique frequency.** Attempted (Clauset-Shalizi-Newman); reported as exploratory given modest N.

Power analysis reported retrospectively at submission.

---

## What the Study Produces

### HCOMP paper contributions, ordered by HCOMP pillars

1. **Complementarity (lead).** AI-assisted refinement increases per-dimension specificity from baseline level X to level Y, isolated from textual-scaffolding effects via the no-AI nudge control. The increase concentrates on [dimensions]; on [other dimensions], AI refinement adds little beyond what a textual nudge already provides. RoundsTaken distribution shows what fraction of free-text descriptions are participant-judged as already complete at first check.
2. **Alignment / fidelity.** Across refinement rounds, semantic-fidelity ratings show [pattern]; forced-fit ratings show [pattern]. The AI's extracted practice preserves participant intent on [dimensions] but distorts on [dimensions]. We characterise where AI-extracted structure aligns with self-report and where it diverges.
3. **Dataset (Human Contributions to AI).** A v0.1 seed practice atlas for stress and anxiety coping: N canonical practices contributed by Prolific participants under three scaffolding regimes, with primitive-level frequencies, per-dimension specificity profiles, and self-reported context-outcome triples.

### Seed Practice Atlas (v0.1)

Released alongside the paper on Zenodo / OSF, CC-BY. Scope:

- Raw practice reports across all conditions (free text plus AI-refined where applicable).
- Per-response specificity codings (Prolific raters, with kappa).
- Initial canonical clustering of Technique values, expert-coded with BCTO criteria, with kappa.
- Dosage and Mode values reported per cluster (not collapsed; variation IS the data).
- Context-outcome triples per response.

### Atlas-level descriptive analyses in the paper

- Number of canonical techniques after expert clustering.
- Empirical Technique frequency distribution; heavy-tail pattern reported descriptively.
- Per-cluster specificity profile across conditions.
- Coverage of stress/anxiety practice space discussed qualitatively.

---

## Open Items (parking lot, not blockers)

1. Final exact N (decided after pilot launch confirms recruitment rate).
2. Choice of LLM and exact prompt for C3 (frozen via internal pilot before launch).
3. Specific Prolific rater recruitment parameters (n_raters, payment, attention checks).
4. Ethics / IRB status.
5. Coauthor identification for canonical clustering.

---

## Implementation TODOs from these locks

The app and supporting docs need updates to match the locked design. None are technically blocking but all should ship before launch:

- `app/steps/input.php`: update C1 and C2 prompt text to include "specifically when you are feeling stressed or anxious".
- `app/steps/consent.php`: update eligibility text; add PSS-4 either at end of consent or as a new step before input.
- `app/steps/refinement.php`: single-screen specificity meter (Check button, LLM 0-3 scoring, soft indicators, optional hints, re-check cap 5). RoundsTaken (open count) tracked in DB. SHIPPED.
- `app/llm.php`: update the system prompt to use "practice" / "primitive" terminology rather than "behavioural gene".
- (Optional cleanup) Internal variable names (`$gene` → `$practice`), CSS class names (`.gene-card` → `.practice-card`), DB column comments. Schema (`gene_extractions` table) can stay; not user-facing.
- `paper/paper.tex`: switch venue declaration from CHI '27 to HCOMP '26 and `acmart` document class option from `manuscript` to `sigconf`.

---

## Key Literature

| Paper | Relevance | DOI |
|-------|-----------|-----|
| Hoffmann et al. (2014) — TIDieR Checklist | Grounds T/D/M specificity coding | 10.1136/bmj.g1687 |
| Michie et al. (2013) — BCT Taxonomy v1 | Technique classification framework | 10.1007/s12160-013-9486-6 |
| Marques et al. (2024) — BCT Ontology | Merge/split criteria for canonical clustering | 10.12688/wellcomeopenres.19363.2 |
| Marques et al. (2021) — Mode of Delivery Ontology | Authoritative ontology for the M dimension | 10.12688/wellcomeopenres.15906.2 |
| Yang et al. (2020) CHI | Isolate AI from interface contributions (motivates C2) | 10.1145/3313831.3376301 |
| Dhillon et al. (2024) CHI | Scaffolding effects in co-writing with LMs | 10.1145/3613904.3642134 |
| Iarygina et al. (2024) IJHCS | Demand characteristics in HCI experiments | 10.1016/j.ijhcs.2024.103379 |
| Rapp & Cena (2016) IJHCS | Scaffolding shapes self-reports | 10.1016/j.ijhcs.2016.05.006 |
| Douglas et al. (2023) PLOS ONE | Prolific data quality benchmarks | 10.1371/journal.pone.0279720 |
| Bhattacharjee et al. (2024) CHI | LLM scaffolding for behaviour change | 10.1145/3613904.3642081 |
| Snow et al. (2008) EMNLP | Crowd annotation matches expert quality with multiple raters | n/a |
| Cohen, Kamarck, Mermelstein (1983) JHSB | Original Perceived Stress Scale; PSS-4 is the brief form derived from this | 10.2307/2136404 |
| Cohen & Williamson (1988) | Source of the PSS-4 four-item short form (book chapter) | n/a |
| Schmalbach et al. (2025) Frontiers in Psychology | PSS-4 psychometric evaluation in a representative population sample; uses GAD-2 as convergent validity | 10.3389/fpsyg.2024.1479701 |
| Kroenke et al. (2007) Annals of Internal Medicine | GAD-2 introduction; ultra-brief anxiety screener used alongside PSS-4 at intake | 10.7326/0003-4819-146-5-200703060-00004 |
| Braun & Clarke (2006) Qualitative Research in Psychology | Reflexive thematic analysis (applied to fidelity open-text responses) | 10.1191/1478088706qp063oa |
| West et al. (2023) | ML extraction reality check (F1=0.42) | 10.12688/wellcomeopenres.20000.1 |
