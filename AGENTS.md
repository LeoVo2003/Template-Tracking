# Project Agent Workflow

This project uses a four-agent workflow for material code tasks:

`Planner → Coder → Tester → Reviewer → User`

## When to use the pipeline

Use the full pipeline for new features, bug fixes, significant refactors, multi-file changes, behaviour or architecture changes, and tasks with regression risk. A small, clear change such as a typo, a single text edit, or one obvious configuration value may use a proportionate, shorter flow.

## Step 1 — Plan

Delegate to `planner`. The Planner investigates the request and creates `.bangiao/ke-hoach.md`.

If that handoff contains material unresolved questions under `CÂU HỎI CÒN BỎ NGỎ`, stop and ask the user before implementation.

## Step 2 — Implement

Delegate to `coder` only after the plan is clear. The Coder reads `.bangiao/ke-hoach.md`, implements only the agreed scope, and creates `.bangiao/thay-doi.md`.

## Step 3 — Test

Delegate to `tester` after implementation. The Tester reads the plan and change handoff, runs the project-appropriate checks, does not fix production code, and creates `.bangiao/ket-qua-test.md`.

If testing fails, send the concrete failure back to Coder, then re-run testing after the fix. Do not treat a failed test as complete.

## Step 4 — Review

Run `reviewer` only after Tester reports PASS. Reviewer reads all handoffs and the Git diff, does not edit production code, and creates `.bangiao/danh-gia.md`.

The first line of the review must be exactly one of:

- `PHAN QUYET: CHOT`
- `PHAN QUYET: CAN SUA`
- `PHAN QUYET: CHAN`

`CAN SUA` returns the work to Coder, then Tester, then Reviewer. `CHAN` stops the task and is reported to the user.

## Safety and project conventions

- Inspect `git status` before work and `git diff` after implementation.
- Never delete, overwrite, or revert unrelated user changes.
- Prefer the existing architecture, conventions, utilities, dependencies, and component patterns.
- Do not refactor, rename broadly, reformat the project, or add dependencies outside the task scope.
- Do not push, merge, deploy, publish, release, alter production data, or use destructive actions unless the user explicitly requests it.
- When the user explicitly requests a plugin update/push and the Reviewer verdict is `PHAN QUYET: CHOT`, commit the scoped changes, push the branch, and push the matching release tag when the release workflow requires one. Report the commit SHA, tag, and release result; do not leave the completed update uncommitted.
- Keep handoff Markdown out of Git by default; `.bangiao/.gitkeep` remains tracked.

## Completion report

Report concisely: what changed, principal files, tests run and result, review verdict, and any remaining issue. Do not dump the entire handoff unless asked.
