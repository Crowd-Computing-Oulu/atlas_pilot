# Plotting theme and statistical helpers for the ATLAS analysis.

suppressPackageStartupMessages({
  library(dplyr)
  library(tidyr)
  library(ggplot2)
})

# Okabe-Ito colourblind-safe palette, mapped to the three conditions.
ATLAS_PAL <- c("C1 Baseline" = "#999999",
               "C2 Nudge"    = "#E69F00",
               "C3 AI coach" = "#0072B2")

theme_atlas <- function(base_size = 12) {
  theme_minimal(base_size = base_size) +
    theme(
      panel.grid.minor = element_blank(),
      panel.grid.major.x = element_blank(),
      legend.position = "bottom",
      plot.title = element_text(face = "bold"),
      strip.text = element_text(face = "bold")
    )
}

# Save a figure to analysis/figures in both PNG (for the doc) and PDF (camera-ready).
save_fig <- function(plot, name, width = 7, height = 4.5) {
  if (!dir.exists("figures")) dir.create("figures", showWarnings = FALSE)
  ggsave(file.path("figures", paste0(name, ".png")), plot, width = width, height = height, dpi = 300)
  ggsave(file.path("figures", paste0(name, ".pdf")), plot, width = width, height = height)
  invisible(plot)
}

# -- Primary: per-dimension cumulative-link (ordinal logistic) model -----------
# Fits clm(level ~ condition) for one dimension and returns the three condition
# contrasts (C2-C1, C3-C1, C3-C2) as proportional-odds ORs with 95% CIs.
# Holm correction is applied OUTSIDE this function across the full family of 9.
fit_ordinal_dimension <- function(df_dim) {
  df_dim <- df_dim |> filter(!is.na(level))
  if (nlevels(droplevels(df_dim$condition)) < 2 || length(unique(df_dim$level)) < 2) {
    return(NULL)
  }
  m <- ordinal::clm(level ~ condition, data = df_dim)
  co <- summary(m)$coefficients
  ci <- confint(m, type = "Wald")

  pull_contrast <- function(term) {
    if (!term %in% rownames(co)) return(NULL)
    tibble(term = term,
           OR = exp(co[term, "Estimate"]),
           conf.low = exp(ci[term, 1]),
           conf.high = exp(ci[term, 2]),
           p.value = co[term, "Pr(>|z|)"])
  }
  base_contrasts <- bind_rows(
    pull_contrast("conditionC2 Nudge"),
    pull_contrast("conditionC3 AI coach")
  )

  # C3-vs-C2: refit with C2 as reference.
  df2 <- df_dim |> mutate(condition = relevel(droplevels(condition), ref = "C2 Nudge"))
  out_c3c2 <- NULL
  if (nlevels(df2$condition) >= 2) {
    m2 <- ordinal::clm(level ~ condition, data = df2)
    co2 <- summary(m2)$coefficients
    ci2 <- confint(m2, type = "Wald")
    if ("conditionC3 AI coach" %in% rownames(co2)) {
      out_c3c2 <- tibble(term = "conditionC3 AI coach",
                         OR = exp(co2["conditionC3 AI coach", "Estimate"]),
                         conf.low = exp(ci2["conditionC3 AI coach", 1]),
                         conf.high = exp(ci2["conditionC3 AI coach", 2]),
                         p.value = co2["conditionC3 AI coach", "Pr(>|z|)"])
    }
  }

  bind_rows(
    base_contrasts |> mutate(contrast = recode(term,
      "conditionC2 Nudge" = "C2 vs C1",
      "conditionC3 AI coach" = "C3 vs C1")),
    if (!is.null(out_c3c2)) out_c3c2 |> mutate(contrast = "C3 vs C2")
  ) |> select(contrast, OR, conf.low, conf.high, p.value)
}

# Fit all three dimensions and Holm-correct across the whole confirmatory family.
fit_primary_models <- function(spec_long) {
  dims <- levels(spec_long$dimension)
  res <- lapply(dims, function(d) {
    out <- fit_ordinal_dimension(filter(spec_long, dimension == d))
    if (is.null(out)) return(NULL)
    out |> mutate(dimension = d, .before = 1)
  })
  res <- bind_rows(res)
  if (nrow(res) == 0) return(res)
  res |> mutate(p.holm = p.adjust(p.value, method = "holm"))
}

# Per-condition predicted category probabilities from a fitted dimension, for plots.
predicted_probs <- function(df_dim) {
  df_dim <- df_dim |> filter(!is.na(level))
  if (length(unique(df_dim$level)) < 2) return(NULL)
  m <- ordinal::clm(level ~ condition, data = df_dim)
  nd <- tibble(condition = factor(levels(droplevels(df_dim$condition)),
                                  levels = levels(df_dim$condition)))
  pr <- predict(m, newdata = nd, type = "prob")$fit
  as_tibble(pr) |>
    mutate(condition = nd$condition) |>
    pivot_longer(-condition, names_to = "level", values_to = "prob")
}

# -- Secondary: Kruskal-Wallis omnibus + Dunn post-hoc (rank-based) ------------
kw_test <- function(df, value, group = "condition") {
  f <- stats::as.formula(paste(value, "~", group))
  stats::kruskal.test(f, data = df)
}

# Dunn's test when dunn.test is available, else Holm-adjusted pairwise Wilcoxon.
posthoc_pairwise <- function(df, value, group = "condition") {
  x <- df[[value]]; g <- droplevels(factor(df[[group]]))
  ok <- !is.na(x) & !is.na(g)
  x <- x[ok]; g <- g[ok]
  if (nlevels(g) < 2) return(NULL)
  if (requireNamespace("dunn.test", quietly = TRUE)) {
    d <- dunn.test::dunn.test(x, g, method = "holm", kw = FALSE, table = FALSE)
    tibble(comparison = d$comparisons, Z = d$Z, p.adj = d$P.adjusted, method = "Dunn (Holm)")
  } else {
    pw <- stats::pairwise.wilcox.test(x, g, p.adjust.method = "holm")
    as.data.frame(as.table(pw$p.value)) |>
      stats::na.omit() |>
      transmute(comparison = paste(Var1, "-", Var2), Z = NA_real_,
                p.adj = Freq, method = "pairwise Wilcoxon (Holm)") |>
      as_tibble()
  }
}
