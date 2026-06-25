# Portfolio Guard PC1 MVP Roadmap (Revision 3)

## Status

**Planning:** Approved for Engineering Planning **Implementation:**
Blocked only by engineering specifications, not product design.

------------------------------------------------------------------------

# Goal

Deliver a production-ready internal security agent for MSP-managed
WordPress sites that:

-   Reliably scans without operator intervention.
-   Automatically remediates only deterministic malware.
-   Surfaces likely new malware families with explainable evidence.
-   Ships with regression testing before every release.
-   Is deployable across the MSP fleet.

------------------------------------------------------------------------

# Guiding Principles

-   Endpoint agent, not client-facing application.
-   Centralized visibility belongs to the future cloud platform.
-   Low false positives are more valuable than maximum detection.
-   Every release must earn trust through automated validation.

------------------------------------------------------------------------

# Phase 1 --- Operational Reliability

## 1.1 Migration & Configuration

-   Remove Safe Mode / Tier 1 Override complexity.
-   Preserve existing Tier 1 safety gates.
-   Define migration from v1.5.x.
-   Simplify remediation policy:
    -   Healthy
    -   Confirmed Malware
    -   Review Required

### Exit Criteria

-   Fresh installs work correctly.
-   Existing installs migrate cleanly.

------------------------------------------------------------------------

## 1.2 Scheduling

Implement:

-   Initial install scan
-   Daily best-effort scan targeting 6:00 AM local site time
-   Catch-up scanning
-   Server cron support documentation

### Exit Criteria

-   Scheduling validated across timezone scenarios.

------------------------------------------------------------------------

## 1.3 Reporting

Replace legacy reporting with:

-   Healthy
-   Confirmed Malware
-   Review Required

Remove "Interesting Findings."

Review Required remains MSP-facing only.

### Exit Criteria

Operators immediately understand scan outcome.

------------------------------------------------------------------------

# Phase 2 --- Validation Infrastructure (Before Detector Changes)

## 2.1 Known Malware Corpus

Expand automated tests to every known family.

------------------------------------------------------------------------

## 2.2 Clean Plugin Corpus

Create pinned-version manifest.

Automatically download representative plugins.

Expected result:

Zero false positives.

------------------------------------------------------------------------

## 2.3 Synthetic Behavior Corpus

Create synthetic plugins that emulate:

-   REST control channels
-   Cookie lifecycle
-   Payload staging
-   Remote callbacks
-   Hidden plugin behavior

Purpose:

Validate behavior detection.

------------------------------------------------------------------------

## 2.4 Automated Release Gate

Single validation command executes:

-   Known malware
-   Clean plugins
-   Synthetic behaviors

Release blocked on failure.

### Exit Criteria

Regression testing exists before heuristic redesign begins.

------------------------------------------------------------------------

# Phase 3 --- Behavioral Detection Engine

## 3.1 Behavior Profile Specification

Complete written engineering specification.

Initial profiles:

-   Persistence
-   Command & Control
-   Payload Delivery
-   Operator Access
-   Stealth

------------------------------------------------------------------------

## 3.2 Feature Extraction Layer

Separate:

Filesystem

↓

Feature Extraction

↓

Classification

Legacy scoring remains unchanged during refactor.

------------------------------------------------------------------------

## 3.3 Behavior Profile Classification

Replace additive heuristic scoring.

Classification becomes profile-based.

Tier 1 remains completely independent.

Tier 2 becomes explainable.

------------------------------------------------------------------------

## 3.4 Explainable Output

Every Review Required report explains:

-   Activated behavior profiles
-   Supporting evidence
-   Human-readable reasoning

No raw heuristic scores exposed.

### Exit Criteria

Behavior engine passes full validation suite.

------------------------------------------------------------------------

# Phase 4 --- Fleet Operations

## 4.1 Signature Separation

Separate signature data from detection engine.

No external signature distribution yet.

------------------------------------------------------------------------

## 4.2 Native WordPress Updates

Implement update infrastructure.

Requirements:

-   Hosted update endpoint
-   WP Admin updates
-   MainWP compatibility
-   Graceful failure if endpoint unavailable

------------------------------------------------------------------------

## 4.3 Engineering Diagnostics

Hidden diagnostics only.

Purpose:

Support MSP engineers.

Not client-facing.

Displays only:

-   Plugin version
-   Last scan
-   Scheduler health
-   Last result summary

No malware dashboard.

------------------------------------------------------------------------

# Required Engineering Specifications

Implementation begins only after completion of:

1.  Migration Plan
2.  Scheduling Specification
3.  Tier 2 Review Workflow
4.  Validation Corpus Manifest
5.  Behavior Profile Specification

These are engineering contracts.

------------------------------------------------------------------------

# Release Criteria

PC1 ships only when:

-   Tier 1 remediation is reliable.
-   Daily scanning is reliable.
-   Validation gate passes.
-   Zero false positives on clean corpus.
-   Known malware corpus passes.
-   Behavior engine produces explainable Review Required findings.
-   Update mechanism functions.
-   MSP engineers can deploy confidently across the fleet.

------------------------------------------------------------------------

# Explicitly Deferred to PC2

-   Cloud management portal
-   Fleet telemetry
-   Cross-site intelligence
-   External signature feeds
-   Customer-facing reporting
-   Analytics
-   Reputation scoring

PC1 focuses exclusively on delivering a trustworthy endpoint agent.
