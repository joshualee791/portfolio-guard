# Portfolio Guard --- Product Source of Truth (PSOT)

**Status:** Engineering Planning Complete\
**Current Baseline:** v1.5.6\
**Target:** Production Candidate 1 (PC1)

------------------------------------------------------------------------

# Mission

Portfolio Guard is an **internal managed security agent** for
MSP-managed SMB WordPress sites.

Its purpose is to:

-   Detect known fake-plugin malware with deterministic signatures.
-   Surface likely new malware families through explainable behavioral
    analysis.
-   Automatically remediate only high-confidence threats.
-   Minimize operational workload for MSP engineers.
-   Operate quietly on client sites while reporting to MSP operations.

Portfolio Guard is **not** intended to compete with Wordfence, Sucuri,
or enterprise endpoint platforms.

------------------------------------------------------------------------

# Product Philosophy

## Guiding Principles

1.  Operator trust is more important than exhaustive detection.
2.  Explainable detections are preferred over opaque scoring.
3.  Tier 1 must remain deterministic.
4.  Tier 2 exists to discover new malware families---not to overwhelm
    operators.
5.  The endpoint is an **agent**, not a dashboard.
6.  Operational visibility belongs in the future centralized management
    platform.

------------------------------------------------------------------------

# Operator Outcomes

Only three operator-visible outcomes exist:

## Healthy

No action required.

## Confirmed Malware

-   Automatic remediation
-   Preserve evidence
-   Notify MSP

## Review Required

-   Preserve evidence
-   Notify MSP
-   Human investigation
-   Future safelist/remediation workflow defined by specification

"Interesting Findings" are removed from the operator experience.

------------------------------------------------------------------------

# PC1 Objectives

## Phase 1 --- Operational Reliability

-   Initial scan after installation
-   Daily best-effort scan targeting 6:00 AM local site time
-   Retain catch-up scanning
-   Remove Safe Mode / Tier 1 Override complexity
-   Preserve existing Tier 1 deletion safety gates
-   Simplify reporting

## Phase 2 --- Validation Infrastructure

Build before modifying the detection engine.

Validation consists of:

-   Known malware corpus
-   Clean plugin corpus
-   Synthetic behavior corpus
-   Automated release gate

No detection engine work proceeds without regression protection.

## Phase 3 --- Behavioral Detection

Introduce:

Filesystem

↓

Feature Extraction

↓

Behavior Profiles

↓

Classification

Initial Behavior Profiles:

-   Persistence
-   Command & Control
-   Payload Delivery
-   Operator Access
-   Stealth

Every Review Required detection must explain why it occurred.

## Phase 4 --- Fleet Operations

-   Native WordPress update support
-   Signature/data separation
-   Hidden engineering diagnostics
-   Prepare for future cloud platform

------------------------------------------------------------------------

# Validation Philosophy

Every release candidate must pass:

-   Known malware corpus
-   Clean plugin corpus
-   Synthetic corpus
-   Regression tests

Failure blocks release.

Every new malware specimen becomes:

1.  Malware corpus entry
2.  Regression test
3.  Future behavior profile input

------------------------------------------------------------------------

# Threat Model

Portfolio Guard is optimized for:

-   Fake WordPress plugins
-   SMB compromise campaigns
-   Managed WordPress environments
-   Repeatable malware ecosystems

Portfolio Guard is not attempting to solve generic PHP malware
detection.

------------------------------------------------------------------------

# Engineering Workflow

Development rhythm:

Specification

↓

Implementation

↓

Validation

↓

Merge

↓

Release Candidate

Specifications are treated as engineering contracts.

------------------------------------------------------------------------

# Required Engineering Specifications

1.  Migration Plan
2.  Scheduling Specification
3.  Tier 2 Review Workflow
4.  Validation Corpus Manifest
5.  Behavior Profile Specification

Implementation begins only after the relevant specification is complete.

------------------------------------------------------------------------

# Success Metrics

PC1 succeeds when:

-   Daily scans execute reliably.
-   Tier 1 malware is remediated automatically.
-   Tier 2 detections are meaningful and explainable.
-   False positives remain rare.
-   Previously unseen fake-plugin families become visible.
-   Every release passes automated validation before deployment.

------------------------------------------------------------------------

# Deferred to PC2

-   Fleet telemetry
-   Central cloud management
-   Cross-site intelligence
-   External signature distribution
-   Reputation scoring
-   Fleet analytics

------------------------------------------------------------------------

# Definition of Done

Portfolio Guard PC1 is complete when:

-   The roadmap is implemented.
-   All engineering specifications are satisfied.
-   Validation gates pass.
-   Deployment across the MSP fleet can occur with confidence.
