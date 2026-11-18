
# E-Commerce — AI Testing Workflow

## 1. Purpose

Define a safe, repeatable testing process for AI coding agents.

The developer remains responsible for approving test execution,
database access, implementation changes and commits.

## 2. Agent Responsibilities

### Backend Agent
- Implement approved features.
- Add relevant tests.
- Report executed and unverified tests.
- Do not approve its own implementation.

### Reviewer Agent
- Independently review implementation and test coverage.
- Report findings with severity and IDs.
- Do not modify code or execute tests.

### Testing Agent
- Analyze test coverage and regression risks.
- Inspect test configuration and database isolation.
- Propose test commands.
- Execute tests only when explicitly authorized and technically permitted.
- Report test evidence and Quality Gate status.
- Do not modify code or approve commits.

## 3. Phase 1 — Test Analysis

Before test execution:

1. Read the approved implementation plan.
2. Inspect changed files and related tests.
3. Inspect phpunit.xml and relevant environment configuration.
4. Identify Unit, Feature and Integration tests.
5. Identify external dependencies and database risks.
6. Produce a Test Execution Plan.
7. Stop and wait for developer approval.

The plan must include:
- Test files and suites
- Exact commands
- Expected test environment
- Database isolation evidence
- External service risks
- Known coverage gaps

## 4. Developer Approval Gate — Test Execution

The developer must explicitly approve:
- Which tests may run
- Which database environment may be used
- Whether external services are permitted
- Any additional setup or test data

Approval does not override technical safety restrictions.

## 5. Phase 2 — Test Execution

Before running approved tests:

1. Recheck the effective test environment.
2. Verify that database connections are isolated.
3. Verify that commands cannot affect shared or production data.
4. Stop if any safety condition is uncertain.

During execution:
- Run only approved commands.
- Do not run manual migrations.
- Do not modify source code or test files.
- Do not commit or push.

After execution:
- Report the exact commands.
- Report passed, failed, skipped and not-run tests.
- Report execution errors separately.
- Identify remaining risks.

## 6. Database Safety

### SQLite Tests

Use SQLite :memory: when supported by the test suite.

Verify:
- APP_ENV=testing
- DB_CONNECTION=sqlite
- DB_DATABASE=:memory:
- DB_URL is empty
- No unexpected external database connections

Laravel test migrations may run automatically only when
the approved test suite and its isolation have been verified.

### MySQL Integration Tests

Use a dedicated, disposable MySQL test database.

Requirements:
- Separate database and credentials
- No production or shared database connections
- Explicit developer approval
- Verified database name and host
- No destructive operations outside the test database

Do not run MySQL integration tests until these conditions
are satisfied.

## 7. Quality Gate Status

### PASS
- Required tests passed.
- No unresolved blocking test failures.
- Approved scope and database safety verified.
- Known limitations documented.

### FAIL
- Required tests failed.
- Blocking regression detected.
- Required quality checks failed.

### BLOCKED
- Test environment cannot be verified.
- Database isolation is uncertain.
- Required approval is missing.
- A required test cannot safely execute.

### INCOMPLETE
- Required tests were not executed.
- Evidence is insufficient to determine PASS or FAIL.

Skipped tests must never be counted as passed.

## 8. Quality Gate Report

Report:

- Feature / commit / branch
- Approved test scope
- Environment verification
- Commands executed
- Tests passed
- Tests failed
- Tests skipped
- Tests not run
- Code quality checks
- Remaining reviewer findings
- Quality Gate status
- Recommendation for developer

## 9. Developer Approval Gate — Commit

Only the developer may approve the commit.

A Quality Gate PASS is a recommendation, not permission
for an agent to commit.

If the gate is FAIL, BLOCKED or INCOMPLETE, explain the
remaining issues and request a developer decision.

## 10. Restrictions

- Never access production databases.
- Never run tests against shared databases.
- Never execute unapproved test commands.
- Never modify files outside approved scope.
- Never claim unexecuted tests passed.
- Never commit or push without explicit approval.
