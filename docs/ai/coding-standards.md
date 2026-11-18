
# E-Commerce Coding Standards

## 1. Technology Stack

- PHP ^8.3
- Laravel 13
- MySQL 8.x
- Laravel Eloquent
- PHPUnit
- Laravel Pint

## 2. General Principles

- Follow existing project conventions.
- Prefer readable and maintainable code.
- Follow PSR-12 where compatible with Laravel conventions.
- Apply SOLID principles pragmatically.
- Avoid unnecessary abstractions.
- Do not introduce dependencies without approval.
- Do not refactor unrelated code.

## 3. Application Structure

Applications:
- api
- web-admin
- web-seller
- web-buyer

Each application has its own:
- Routes
- Controllers
- Models
- Configuration
- Tests

Do not assume that all applications use identical models.

## 4. Controller Rules

- Keep controllers focused on HTTP responsibilities.
- Validate incoming requests.
- Use Form Requests when validation becomes complex.
- Return consistent HTTP status codes.
- Do not expose internal exception details.
- Use services when business logic becomes complex.

## 5. Service Layer

- Introduce services when business logic needs separation.
- Keep business rules independent of HTTP concerns where practical.
- Do not create empty services merely to follow a pattern.
- Use dependency injection where appropriate.

## 6. Repository Layer

- Eloquent is the default data access approach.
- Do not introduce repositories automatically.
- Add a repository only when there is a clear architectural benefit.

## 7. Database

Follow docs/ai/database-rules.md.

- Avoid N+1 queries.
- Use transactions when required.
- Use parameterized queries.
- Review schema changes across all applications.

## 8. API Design

- Use appropriate RESTful conventions.
- Validate requests.
- Use consistent JSON response structures.
- Return appropriate HTTP status codes.
- Apply authentication and authorization.
- Do not expose sensitive data.

## 9. Security

- Never hardcode secrets.
- Use Laravel validation and authorization.
- Prevent mass assignment vulnerabilities.
- Escape untrusted output appropriately.
- Do not disable security middleware without approval.

## 10. Testing

- Add tests for new business logic where practical.
- Test successful and failure scenarios.
- Test authorization boundaries.
- Test transaction and concurrency-sensitive logic.
- Do not claim tests passed unless executed successfully.

## 11. Coding Agent Workflow

1. Read project instructions.
2. Analyze existing code.
3. Identify affected applications.
4. Propose a plan.
5. Wait for approval.
6. Implement approved changes.
7. Run safe tests.
8. Review changes.
9. Report results.

## 12. Git Rules

- Work on a feature branch.
- Do not modify unrelated files.
- Never commit or push without approval.
- Never force-push automatically.
- Review git diff before completion.
