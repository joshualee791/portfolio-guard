# Portfolio Guard — Release Automation

GitHub Actions is the authoritative release pipeline for Portfolio Guard. Pushing a version tag triggers a workflow that runs the existing build and validation scripts, then publishes a GitHub Release with all artifacts.

## How it works

The workflow (`.github/workflows/release.yml`) orchestrates the existing engineering pipeline without replacing any part of it:

1. **Corpus staging** — downloads and verifies the clean plugin corpus (`validation/runner/stage-clean-corpus.ps1`)
2. **Build** — runs `portfolio-guard/scripts/build-release.ps1`, which internally:
   - Verifies PHP and the ZipArchive extension
   - Runs the full validation gate (`php validation/gate.php`)
   - Verifies that the plugin header, `MSP_PG_VERSION` constant, `readme.txt` Stable tag, and changelog entry all match the release version
   - Builds the release ZIP
   - Runs the release package test (`php validation/release-package-test.php`)
   - Writes the SHA-256 checksum file
3. **Manifest** — generates `plugin.json` from the build artifacts and `readme.txt` metadata
4. **Release** — creates a GitHub Release and uploads the ZIP, SHA-256, and `plugin.json` as assets

The release fails immediately at any step if validation, build, or artifact checks do not pass. No GitHub Release is published unless all checks succeed.

## Releasing a new version

Before tagging, the following must all reflect the new version number:

| Location | Field |
|---|---|
| `portfolio-guard/portfolio-guard.php` header | `Version:` |
| `portfolio-guard/portfolio-guard.php` | `MSP_PG_VERSION` constant |
| `portfolio-guard/readme.txt` | `Stable tag:` |
| `portfolio-guard/readme.txt` | Changelog entry (`= X.Y.Z =`) |
| `portfolio-guard/readme.txt` | Upgrade notice (`= X.Y.Z =`) |

When those are committed, tag and push:

```bash
git tag v2.0.4
git push origin v2.0.4
```

The workflow triggers automatically on the `v*` tag. Monitor progress in the Actions tab.

## Artifacts

Each release publishes three assets:

| File | Purpose |
|---|---|
| `portfolio-guard-X.Y.Z.zip` | WordPress plugin ZIP for manual installation or WP updater download |
| `portfolio-guard-X.Y.Z.sha256` | SHA-256 checksum for integrity verification |
| `plugin.json` | Release manifest consumed by the WordPress update notifier |

## Example plugin.json

```json
{
  "version": "2.0.3",
  "download_url": "https://github.com/joshualee791/portfolio-guard/releases/download/v2.0.3/portfolio-guard-2.0.3.zip",
  "requires": "5.0",
  "tested": "6.8",
  "requires_php": "7.4",
  "sha256": "cc49c598e803920d2ba1a26fdc461732dd607c30012a4dd62c32f6cc1007f999",
  "release_notes_url": "https://github.com/joshualee791/portfolio-guard/releases/tag/v2.0.3",
  "changelog": "See release notes: https://github.com/joshualee791/portfolio-guard/releases/tag/v2.0.3"
}
```

## How the WordPress updater discovers releases

`MSP_PG_PluginUpdater` fetches `MSP_PG_Config::plugin_update_url()`, which returns:

```
https://github.com/joshualee791/portfolio-guard/releases/latest/download/plugin.json
```

GitHub redirects `releases/latest/download/plugin.json` to the `plugin.json` asset on the most recent non-prerelease release. No API authentication is required for public repositories.

When a site's installed version is older than the `version` field in the fetched manifest, WordPress surfaces an update notification in the standard admin UI. The operator downloads and installs through the native WordPress updater, which fetches from `download_url`.

## Prerelease tags

Tags containing a hyphen (e.g., `v2.0.4-rc.1`) are published as GitHub prereleases and are not marked as latest. The `releases/latest/download/plugin.json` redirect is unaffected — it continues to point to the most recent stable release.

## Required permissions

The workflow uses `GITHUB_TOKEN` (automatically available in all Actions runs). No secrets need to be configured. The token requires:

- **Contents: write** — to create the GitHub Release and upload assets (granted by the `permissions: contents: write` declaration in the workflow)

## Recovery

If a release fails mid-workflow (e.g., network error during corpus staging), re-run the workflow from the Actions tab. All steps are idempotent:
- Corpus staging skips already-staged directories
- Build overwrites the existing ZIP and SHA-256
- `gh release create` will fail if the tag already has a release — delete the draft release first, then re-run

If the build script's version verification fails, update the missing field, commit, delete the tag, and re-tag:

```bash
git tag -d v2.0.4
git push origin :refs/tags/v2.0.4
# fix the missing field, commit
git tag v2.0.4
git push origin v2.0.4
```
