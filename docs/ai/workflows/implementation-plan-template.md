
# Implementation Plan Template

> Status: DRAFT — Awaiting Developer Approval
>
> This document is a reusable template. Replace placeholders
> for each feature. Do not implement before approval.

## 1. Feature Overview

**Feature Name:** [Feature name]

**Requested By:** Developer

**Target Application:** [api / web-admin / web-seller / web-buyer]

**Objective:**
[Describe the business goal and expected behavior.]

**Scope:**
- [In-scope requirement 1]
- [In-scope requirement 2]

**Out of Scope:**
- [Explicitly excluded behavior]

## 2. Existing System Analysis

**Relevant Components:**
- Routes: [Path]
- Controllers: [Path]
- Models: [Path]
- Services / Repositories: [Paths, if applicable]
- Migrations: [Path]
- Tests: [Path]

**Current Behavior:**
[Explain what the existing code actually does.]

**Dependencies:**
[Identify related applications, APIs, and database tables.]

**Unknowns:**
[List any missing requirements or unverified assumptions.]

## 3. Proposed Implementation

Describe the implementation in ordered steps.

1. [Step 1]
2. [Step 2]
3. [Step 3]

### Files to Create

| File | Purpose |
|------|---------|
| [Path] | [Reason] |

### Files to Modify

| File | Proposed Change |
|------|-----------------|
| [Path] | [Description] |

### Files Not to Modify

- [Path or scope]

Do not modify files outside the approved scope.

## 4. Database Impact

**Database:** MySQL 8.x

**Shared Database:** Yes

**Migration Owner:** api/database/migrations

**Schema Changes Required:** [Yes / No]

If yes, describe:
- Tables and columns
- Data types and constraints
- Indexes and foreign keys
- Backward compatibility
- Rollback and deployment risks

**Data Migration Required:** [Yes / No]

**Query Performance Considerations:**
[Indexes, pagination, query plans, N+1 risks.]

No migrations or database modifications
without separate developer approval.

## 5. API Contract

**Endpoint:** [HTTP method and path]

**Authentication:** [Public / Authenticated / Role]

**Request Parameters:**

| Parameter | Type | Required | Validation |
|-----------|------|----------|------------|
| [Name] | [Type] | [Yes/No] | [Rule] |

**Successful Response:**

```json
{
  "data": []
}
```

**Error Responses:**
- 400: [When applicable]
- 401: [When applicable]
- 403: [When applicable]
- 404: [When applicable]
- 422: [Validation errors]
- 500: [Unexpected errors; do not expose internals]

Only document status codes that apply to this feature.

**Compatibility Notes:**
[Breaking changes, field types, currency units,
existing client dependencies.]

## 6. Testing Strategy

**Unit Tests:**
- [Test case]

**Feature Tests:**
- [Test case]

**Boundary Cases:**
- [Test case]

**Database-Specific Tests:**
- [MySQL-only cases, if any]

**Test Environment:**
[SQLite :memory:, isolated MySQL test database, etc.]

**Verification Commands:**
- [Command and working directory]

**Safety Checks:**
- Confirm environment before tests.
- Confirm test database isolation.
- Do not run migrations against production or shared DBs.
- Distinguish executed, skipped, and untested cases.

## 7. Risks & Assumptions

| Severity | Risk / Assumption | Mitigation |
|----------|-------------------|------------|
| High | [Risk] | [Mitigation] |
| Medium | [Risk] | [Mitigation] |
| Low | [Risk] | [Mitigation] |

**Open Questions:**
1. [Question]
2. [Question]

**Deferred Improvements:**
- [Improvement and reason for deferral]

## 8. Developer Approval

**Status:** PENDING

**Approved Scope:**
[To be completed by developer.]

**Approved Files:**
[Explicit list of permitted files.]

**Approved Database Changes:**
[None / Specific approved changes.]

**Approved Test Execution:**
[Environment and commands.]

**Restrictions:**
- No implementation before approval.
- No unauthorized migrations.
- No production database access.
- No commit or push without approval.

**Approval Decision:**
[PENDING / APPROVED / APPROVED WITH CONDITIONS /
REJECTED]

**Developer Notes:**
[Conditions or requested revisions.]

---

## Agent Output Rules

When using this template, the Backend Agent must:

1. Inspect the actual project before proposing changes.
2. Distinguish verified facts from assumptions.
3. Use real repository paths.
4. Identify affected applications in the shared database.
5. Explicitly describe database and API compatibility risks.
6. Provide a test plan with environment safety checks.
7. Stop after presenting the plan.
8. Wait for explicit developer approval before modifying code.

A completed implementation plan is not authorization
to implement the feature.
