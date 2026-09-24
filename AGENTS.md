# Project Working Style

For all UI/layout work in this repository, read docs/MAC-BOTANICAL-UI.md first.
The MAC Botanical UI design system is the visual source of truth.
Do not use the generic Premium TypeUI skill as the primary UI authority for this project.
Do not change business logic during UI work unless the user explicitly requests it.

Work directly in one continuous task. Do not use the Planner -> Coder -> Tester -> Reviewer subagent pipeline unless the user explicitly asks for delegation.

## Markdown-first implementation

- When the user supplies a Markdown specification, read it completely and implement it in the order and scope it defines.
- Treat the supplied specification as the source of truth. Raise a concise question only when it contains a genuine blocking ambiguity.
- For a phased specification, do not jump ahead of the phase the user requested.
- Report meaningful progress in the main conversation; do not create `.bangiao/` handoff files unless the user explicitly requests them.

## Delivery process

- Inspect `git status` before work and `git diff` after implementation.
- Implement, then run tests and lint checks proportionate to the change in the same task.
- If a check fails, diagnose and fix it before calling the work complete.
- Never delete, overwrite, or revert unrelated user changes.
- Prefer the existing architecture, conventions, utilities, dependencies, and component patterns.
- Do not refactor, rename broadly, reformat the project, or add dependencies outside the requested scope.
- Do not push, merge, deploy, publish, release, or alter production data unless the user explicitly requests it or has given a standing instruction to push completed work.
- Standing delivery instruction: after completing a requested WordPress plugin implementation, commit only the task-related files, push `origin/main`, bump the patch version in both plugin version declarations, then create and push the matching `v<version>` release tag so the GitHub workflow can build the WordPress update. Do not apply this release path when the user explicitly asks for local-only, draft, review-only, or different delivery work.
- Keep local agent configuration such as `.agents/` out of commits.

## Completion report

Report concisely: what changed, principal files, tests run and result, and any remaining action the user must take.
