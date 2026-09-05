
### 2026-08-13 — reported history rewrite: investigated, NOT reproducible; ledger SHAs explained instead
User reported that commit **descriptions** were rewritten ~2–4 weeks ago (source unchanged, hashes
changed as a result). **Measured across all 35 repos under `/home/sites/phlix`: no rewrite is
visible.** `git fetch origin` on every repo left **every upstream ref unchanged**, and every SHA this
session recorded still resolves. If a rewrite happened it has not reached these origins.

**Two signals that looked like rewrite fingerprints both have innocent explanations — do not cite
either as evidence:**
1. **Committer date == author date on all 1424 phlix-server commits.** A `filter-branch` normally
   piles committer dates onto the rewrite day; nothing of the sort. Inconclusive at best.
2. 🔴 **CORRECTION to this session's batch-02 note.** I recorded S12's `14f5ee81` as *"a SHA that is
   not real."* **It is real** — it exists in phlix-ui, it is simply **not an ancestor of master**.
   It is the **pre-squash branch commit**; master carries the squashed `f7d671bf`. Proven on three
   more cases: `1475840d` ("docs(changelog): S47 unlink…") is off-master while master holds the
   squash `f3a41f5b S47: … (#555)`. ⇒ **This program squash-merges with `--delete-branch`, so a
   ledger SHA taken from a branch NEVER becomes an ancestor of master. That is the normal state, not
   corruption.**

**Ledger SHA resolvability, measured** (100 SHAs from the original July status index):
| result | n | meaning |
|---|---|---|
| ancestor of master | 71 | fine |
| exists, off-master | 27 | pre-squash branch commits — expected |
| absent entirely | 2 → **1** | `5d337e7` was a false alarm (it IS on phlix-contracts master; my probe omitted that repo). Only `a1f7024` is unaccounted for. |

**Operational rules — these hold whether or not a rewrite ever occurred:**
- 📌 **A recorded SHA is NOT evidence a step shipped, and a SHA failing to resolve on master is NOT
  evidence it did not.** ~27% legitimately do not resolve. Verify by **content on master**, never by
  hash.
- 📌 **After any rewrite, compare TREES, not commits** (already program doctrine from the disjoint-tag
  incident). Pre-rewrite tree maps for 7 repos captured at `scratchpad/pre-rewrite/*.master.map`
  (commit → tree → subject), so an old SHA can be re-mapped by tree equality if one ever lands.
  ⚠ 15 trees repeat on phlix-server master, so tree-matching is ambiguous for those.
- ✅ **The audit is UNAFFECTED.** Auditors re-derive SHAs from current history rather than trusting
  recorded ones, and step-number greps still resolve (S12/S15/S16/S19/S21 each match 2 commits on
  phlix-ui master) ⇒ **subjects still name their steps, so the lookup key survived.** Had subjects
  been rewritten, a failed grep would have produced **false NOT-FOUND verdicts** — the failure mode
  to watch for if a rewrite does land mid-audit.
