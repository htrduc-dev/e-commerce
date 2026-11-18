
# Code Review Report Template

> Review Status: DRAFT
>
> This template defines the expected output of the
> Claude Reviewer Sub-Agent for E-Commerce features.

## 1. Review Information

**Feature Name:** [Feature name]

**Branch:** [Branch name or NOT VERIFIED]

**Reviewer:** Claude Reviewer Sub-Agent

**Review Date:** [Date]

**Implementation Plan:** [Reference, if available]

**Files Reviewed:**
- [File path]
- [File path]

**Review Scope:**
[Describe what is included and excluded.]

## 2. Executive Summary

**Overall Assessment:**
[Briefly describe implementation quality.]

**Recommendation:**
[APPROVE / APPROVE WITH CHANGES / REQUEST CHANGES]

**Blocking Findings:** [Count]

**Non-Blocking Findings:** [Count]

**Unverified Areas:**
- [Items that could not be verified]

Do not report unexecuted tests as passed.

## 3. Findings

Use one subsection per finding.

### Finding REV-001

**Severity:** [Critical / High / Medium / Low]

**Status:** [Open / Resolved / Deferred]

**File:** [Exact repository path]

**Line:** [Line number or range]

**Category:** [Correctness / Security / Database /
Performance / API Contract / Testing / Maintainability]

**Description:**
[Explain the issue and its impact.]

**Evidence:**
[Describe the code or behavior that supports the finding.]

**Reproduction Scenario:**
[Concrete input or steps, if available.]

**Recommended Fix:**
[Specific corrective action.]

**Verification:**
[How to confirm the fix works.]

**Developer Decision:** [Pending / Approved Fix /
Accepted Risk / Deferred]

## 4. Review Checklist

### Functional Correctness
- [ ] Requirements are implemented.
- [ ] Edge cases are handled.
- [ ] Error responses are appropriate.
- [ ] No unintended behavior changes.

### Security
- [ ] Authentication and authorization are correct.
- [ ] Input validation is sufficient.
- [ ] No sensitive data is exposed.
- [ ] No SQL injection or unsafe queries.
- [ ] No credentials or secrets are committed.

### Database
- [ ] Eloquent models and casts are appropriate.
- [ ] Data types preserve precision.
- [ ] Transactions are used where required.
- [ ] Queries avoid unnecessary N+1 problems.
- [ ] Index requirements are evaluated.
- [ ] Shared database ownership rules are respected.
- [ ] MySQL-specific behavior is identified.

### API Contract
- [ ] Request and response fields match requirements.
- [ ] JSON field types are correct.
- [ ] Pagination and sorting are deterministic.
- [ ] Validation errors use expected status codes.
- [ ] Breaking changes are documented.
- [ ] Currency and monetary units are defined
      or explicitly marked as unresolved.

### Testing
- [ ] Tests cover successful requests.
- [ ] Tests cover validation failures.
- [ ] Boundary cases are covered.
- [ ] Database-specific cases are identified.
- [ ] Tests were actually executed and verified.
- [ ] Skipped tests are clearly reported.
- [ ] Test database isolation is verified.

### Scope and Git
- [ ] Changes match the approved implementation plan.
- [ ] No unrelated files were modified.
- [ ] Staged changes have been reviewed.
- [ ] No unauthorized migrations were added.
- [ ] No unauthorized commit or push occurred.

Use N/A or NOT VERIFIED when a check cannot
be confirmed. Do not assume a PASS.

## 5. Test Verification

**Tests Executed By Reviewer:** [Yes / No]

**Test Environment:** [Verified / Reported / Unknown]

**Test Commands:**
- [Command, if actually executed]

**Results:**
- Passed: [Number or NOT VERIFIED]
- Failed: [Number or NOT VERIFIED]
- Skipped: [Number or NOT VERIFIED]
- Assertions: [Number or NOT VERIFIED]

**Evidence Source:**
[Reviewer execution / Developer-provided output /
Agent-reported result / Not available]

**Database-Specific Tests:**
[Executed / Skipped / Not Discovered / Not Configured]

**Remaining Test Gaps:**
- [Gap]

Never describe a test as independently verified
unless the reviewer executed it or inspected
reliable execution evidence.

## 6. Required Changes Before Commit

1. [Required change or None]
2. [Required change or None]

For each required change, reference a finding ID.

Do not automatically authorize the implementation
of recommended changes.

## 7. Deferred Improvements

| Finding ID | Improvement | Risk | Follow-up |
|------------|-------------|------|-----------|
| [ID] | [Description] | [Risk] | [Task] |

Deferred risks require explicit developer acceptance.

## 8. Final Recommendation

**Decision:**
[APPROVE / APPROVE WITH CHANGES / REQUEST CHANGES]

**Reasoning:**
[Explain the decision using evidence.]

**Conditions Before Commit:**
- [Condition]

**Conditions Before Production Release:**
- [Condition]

**Developer Approval Required:** Yes

## 9. Re-Review History

| Round | Finding | Fix Status | Verification |
|-------|---------|------------|--------------|
| 1 | [REV-001] | [Status] | [Evidence] |
| 2 | [REV-002] | [Status] | [Evidence] |

A finding is RESOLVED only when the reviewer
has evidence that the issue was addressed.

---

## Reviewer Agent Rules

1. Read CLAUDE.md and relevant docs/ai rules.
2. Inspect actual source files before reporting findings.
3. Review against the approved implementation plan.
4. Report precise file paths and line numbers.
5. Distinguish confirmed defects from potential risks.
6. Distinguish executed tests from reported results.
7. Do not modify files during review.
8. Do not run migrations or connect to production.
9. Do not commit or push.
10. Do not claim a finding is resolved without evidence.
11. Stop after reporting findings and recommendations.
12. Wait for developer approval before any fix begins.

## Severity Definitions

**Critical:** Security compromise, severe data loss,
or an immediately exploitable system failure.

**High:** Incorrect business-critical behavior,
data integrity problems, or a major security defect.

**Medium:** Significant edge-case, performance,
compatibility, or maintainability risk.

**Low:** Minor improvement, documentation issue,
or low-impact technical debt.

Severity depends on actual impact and likelihood,
not just the existence of a potential issue.
