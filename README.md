# Portfolio Guard Repository

This repository contains the active `Portfolio Guard` WordPress security plugin, its malware research corpus, and the supporting documentation used to develop family-specific detection and remediation logic.

## Repository Layout

- `portfolio-guard/` - active plugin source, tests, boot files, and build script
- `malware-corpus/specimens/` - preserved malware specimens used for reverse engineering and signature development
- `malware-corpus/neutralized-artifacts/` - sanitized preservation artifacts that are useful for lineage and retention analysis
- `docs/` - architecture notes, release notes, and project documentation
- `research/` - malware intelligence reports and case writeups
- `releases/` - generated packaging output and historical build artifacts
- `archive/` - inventory snapshots and other generated repository artifacts

## Malware Handling

- Treat everything under `malware-corpus/` as untrusted.
- Do not execute specimen code outside an isolated analysis environment.
- Prefer static review, hashing, and controlled diffing over live execution.

## Development Workflow

- Make source changes in `portfolio-guard/`.
- Keep malware-family analysis and signature rationale in `research/` and `docs/`.
- Run the self-contained test harness from `portfolio-guard/tests/` when a local PHP runtime is available.

## Build Workflow

- Build release ZIPs with `portfolio-guard/scripts/build-wordpress-plugin-zip.ps1`.
- Generated ZIPs belong under `releases/` and are ignored for the first Git baseline.

## Release Workflow

- Update plugin version metadata in `portfolio-guard/`.
- Record release notes in `docs/releases/`.
- Generate a fresh ZIP only when preparing a distributable build.
