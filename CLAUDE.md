## Shared Project Rules

Read before performing project work:

- docs/ai/architecture.md
- docs/ai/database-rules.md
- docs/ai/coding-standards.md

Database: MySQL 8.x.
Shared business migrations: api/database/migrations.


# E-Commerce Claude Code Instructions

This is a Laravel monorepo with multiple applications
sharing one business database.

Read the following documents:
- docs/ai/architecture.md
- docs/ai/database-rules.md
- docs/ai/coding-standards.md

## Working Principles
- Communicate in Vietnamese.
- Analyze before implementation.
- Follow existing project conventions.
- Explain affected applications and database tables.
- Request approval before editing source code.

## Database Rules
- Shared migrations are owned by api/database/migrations.
- Do not modify production data.
- Do not run migrations without approval.
- Evaluate schema compatibility across applications.

## Agent Responsibilities
- Backend: Laravel API and backend code.
- Database: Schema, migrations, Eloquent and SQL review.
- Web: Admin, seller and buyer applications.
- Reviewer: Security, code quality and testing.

## Safety
- Never push, merge or deploy without approval.
- Do not expose credentials.
- Do not modify unrelated files.
