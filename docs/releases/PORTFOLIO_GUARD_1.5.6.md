# Portfolio Guard 1.5.6

## Summary

- Reorganizes the repository into dedicated top-level areas for active source, malware corpus, documentation, research, releases, tooling, signatures, and archival material.
- Adds Tier 1 exact-match registry support for `macrolayer-macroflag`.
- Preserves existing detection and remediation behavior while extending confirmed-family coverage.

## Included Changes

- Portfolio Guard source now lives in `portfolio-guard/`.
- Known-family specimens now live under `malware-corpus/specimens/fake-plugin-ecosystem/known-families/`.
- Atlas-ecosystem specimens now live under `malware-corpus/specimens/fake-plugin-ecosystem/atlas-ecosystem/`.
- Neutralized preservation artifacts now live under `malware-corpus/neutralized-artifacts/`.
- Added `macrolayer-macroflag` to the built-in Tier 1 signature registry with:
  - primary plugin file
  - exact PHP payload hashes
  - known malicious domains
  - exact REST namespace
  - family IOC strings
- Updated unit coverage to verify Tier 1 classification and remediation eligibility for `macrolayer-macroflag`.

## Version

- `1.5.5` -> `1.5.6`
