## Shared Project Rules

Read before performing project work:

- docs/ai/architecture.md
- docs/ai/database-rules.md
- docs/ai/coding-standards.md

Database: MySQL 8.x.
Shared business migrations: api/database/migrations.


# E-Commerce Coding Agent Instructions

Read these documents before starting:
- docs/ai/architecture.md
- docs/ai/database-rules.md
- docs/ai/coding-standards.md

## General Rules
- Respond in Vietnamese.
- Follow the existing Laravel architecture.
- Do not introduce new dependencies without approval.
- Do not modify unrelated applications.
- Preserve backward compatibility.

## Workflow
1. Understand the user's request.
2. Inspect relevant source files.
3. Identify affected applications and tables.
4. Propose an implementation plan.
5. Wait for approval before editing.
6. Implement only the approved scope.
7. Run safe, relevant tests.
8. Report changes, test results and risks.

## Database Safety
- Never access production databases.
- Never execute destructive SQL.
- Never run migrations without approval.
- All shared schema changes belong to api/database/migrations.
- Review transaction boundaries and concurrent writes.

## Git Safety
- Never push, merge or deploy automatically.
- Do not commit without approval.
- Do not modify unrelated files.
