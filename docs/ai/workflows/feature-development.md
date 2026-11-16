
# Feature Development Workflow

## 1. Purpose

Define a consistent workflow for implementing E-Commerce features using Codex and Claude Code.

All agents must follow project rules in:
- AGENTS.md
- CLAUDE.md
- docs/ai/architecture.md
- docs/ai/database-rules.md
- docs/ai/coding-standards.md

## 2. Workflow Stages

### Stage 1 — Analysis & Planning

Owner: Codex Backend Agent

Responsibilities:
- Understand feature requirements.
- Inspect relevant source code.
- Identify affected applications and files.
- Propose implementation steps.
- Identify database changes and risks.
- Propose testing strategy.

Output:
- Implementation plan.
- List of files to change.
- Risks and assumptions.

Rules:
- Do not modify files.
- Do not run migrations.
- Wait for developer approval.

### Stage 2 — Developer Approval

Owner: Developer

The developer must explicitly approve:
- Implementation scope.
- Files allowed to change.
- Database migrations, if any.
- Testing strategy.

No implementation may begin without approval.

### Stage 3 — Implementation

Owner: Codex Backend Agent

Responsibilities:
- Implement only approved changes.
- Follow Laravel coding standards.
- Add or update relevant tests.
- Report changed files and test results.

Rules:
- Do not modify files outside approved scope.
- Do not run migrations without separate approval.
- Do not commit or push.
- Do not connect to production services.

### Stage 4 — Independent Code Review

Owner: Claude Reviewer Agent

Review:
- Correctness and edge cases.
- Security and data exposure.
- API compatibility.
- Database integrity and performance.
- Test coverage.
- Compliance with approved scope.

Output:
- Findings with severity and file locations.
- Required fixes.
- Recommendation: APPROVE, APPROVE WITH CHANGES, or REQUEST CHANGES.

Rules:
- Read-only review.
- Do not modify source code.
- Do not run migrations or database commands.

### Stage 5 — Fix & Re-Review

Owner: Developer + Codex + Claude

Process:
1. Developer reviews findings.
2. Developer approves the fixes.
3. Codex implements approved fixes.
4. Claude reviews changes again.

Repeat until blocking findings are resolved or explicitly documented as deferred risks.

### Stage 6 — Verification

Owner: Developer

Verify:
- Tests were actually executed.
- Test environment is isolated.
- Code formatting checks pass.
- Git diff contains only approved changes.
- Known limitations are documented.

Do not treat skipped or undiscovered tests as passed.

### Stage 7 — Commit Approval

Owner: Developer

Before committing:
- Confirm branch.
- Review staged diff.
- Confirm no unrelated files are staged.
- Record outstanding risks.

Commit only after explicit developer approval.
Push and merge require separate approval.

## 3. Safety Rules

- Never run destructive database commands without explicit approval.
- Never use production databases for tests.
- Never commit secrets or credentials.
- Never assume agent-reported tests have been independently verified.
- Agent instructions do not replace runtime permissions and sandbox controls.

## 4. Workflow Artifacts

- Implementation plan:
  docs/ai/workflows/implementation-plan-template.md

- Review report:
  docs/ai/workflows/code-review-template.md

## 5. Definition of Done

A feature is ready for a development commit when:
- Approved implementation scope is complete.
- No unresolved blocking review findings remain.
- Required local tests pass in a verified environment.
- API contracts and known limitations are documented.
- Developer has reviewed and approved the staged changes.

A successful commit does not automatically mean the feature is production-ready.
