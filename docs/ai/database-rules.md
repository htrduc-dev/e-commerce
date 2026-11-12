
# E-Commerce Database Rules

## 1. Database Architecture

- Database engine: MySQL 8.x.
- ORM: Laravel Eloquent.
- All Laravel applications share one business database.
- Applications: api, web-admin, web-seller, web-buyer.
- Shared business migrations are owned by api/database/migrations.

## 2. Schema Ownership

- Only the api application owns shared business migrations.
- Other applications must not create migrations for shared business tables.
- Every schema change requires developer approval.
- Review compatibility across all applications before changing columns.
- Prefer backward-compatible schema changes.

## 3. Eloquent Models

- Each Laravel application maintains its own Eloquent models.
- Follow existing model naming and relationship conventions.
- Use explicit fillable or guarded configuration.
- Use casts for appropriate data types.
- Avoid unnecessary eager loading.
- Identify and prevent N+1 queries.
- Do not assume models in different applications are identical.

## 4. SQL Query Standards

- Use Eloquent or Query Builder where appropriate.
- Use parameter binding for raw SQL.
- Never concatenate untrusted input into SQL.
- Select only required columns when practical.
- Review indexes for frequently executed queries.
- Use EXPLAIN to analyze slow queries when safe.
- Do not create indexes without evaluating write overhead.

## 5. Transactions and Concurrency

- Use database transactions for atomic business operations.
- Consider row-level locking when concurrent writes are possible.
- Keep transactions short.
- Define consistent lock ordering where multiple rows are locked.
- Handle deadlocks and retries where appropriate.
- Never assume a transaction alone prevents all race conditions.

## 6. Money and Inventory

- Follow the project's documented monetary unit.
- Do not use floating-point types for exact monetary values.
- Never change monetary units without explicit approval.
- Prevent negative inventory where business rules prohibit it.
- Review concurrent inventory updates.
- Ensure order and payment operations are idempotent where required.

## 7. Data Integrity

- Use appropriate primary and foreign keys.
- Define unique constraints for business uniqueness.
- Validate input at application boundaries.
- Do not rely only on application validation.
- Evaluate nullable fields and default values carefully.
- Preserve referential integrity.

## 8. Migration Safety

- Never run migrations against production.
- Never execute migrate:fresh or migrate:reset without approval.
- Never drop tables or columns without explicit approval.
- Do not modify historical migrations already deployed.
- Prefer new forward migrations.
- Document rollback limitations and data-loss risks.

## 9. Shared Database Compatibility

Before proposing a schema change:

1. Identify affected tables and columns.
2. Find references across all applications.
3. Identify affected Eloquent models.
4. Identify affected queries and business workflows.
5. Assess backward compatibility.
6. Propose migration and rollback strategies.
7. Request developer approval.

## 10. Agent Safety

Default mode: READ-ONLY ANALYSIS.

- Do not modify database schemas automatically.
- Do not connect to production databases.
- Do not read or expose secrets.
- Do not run data-changing SQL without approval.
- Do not commit or push without approval.

Always report:
- Findings
- Evidence and file paths
- Proposed changes
- Risks
- Tests required
- Items requiring approval
