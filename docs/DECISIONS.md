# Architectural Decisions

## New Signup Role Default (2026-07-23)

**Decision:** New user signups default to the `Viewer` role.

**Rationale:** Per roadmap Section 8, new accounts should start with minimal privileges (Viewer) and require explicit Administrator promotion to gain upload/approval rights. This prevents accidental privilege escalation and aligns with the principle of least privilege.

**Status:** Interim — pending explicit client/stakeholder confirmation. May change based on final product requirements.

**Implementation:** Explicitly set in `app/Http/Controllers/Api/AuthController.php::signup()` (not relying on migration default) to ensure the decision is visible in code and testable.
