# WordPress Malware Case Report: Technical Stakeholders

Date: 2026-06-03

## Scope

This report covers four preserved WordPress plugin directories recovered from a compromised website:

- `laravel-janet`
- `framework-triappment`
- `platformist-quadendpointer`
- `smartrestal-serverful`

The plugins were not active at the time of recovery. Analysis is based on preserved filesystem artifacts and code review of the recovered plugin trees.

## Executive Conclusion

This specimen is a shared malware framework deployed as multiple fake WordPress plugins rather than four independent threats.

Core confirmed behaviors:

- Hides itself from the WordPress plugin list.
- Exposes operator backdoors that set a WordPress auth cookie for an arbitrary user ID.
- Stages browser-side JavaScript from external infrastructure.
- Calls home to remote `/api/config/<n>` and `/api/click` endpoints.
- Uses shared encrypted/bootstrap logic across plugins.

Confidence:

- High: shared framework, unauthenticated access paths, remote staging, plugin concealment.
- Medium: some very large helper files appear builder-generated and may contain filler code alongside active routines.

## Specimen Architecture

### Shared Framework Assessment

The four plugins are components of one malware family.

Evidence:

- Near-identical top-level structure across plugin trees.
- Repeated hook layout in the four main plugin files.
- Hidden secondary payload files under short random subdirectories:
  - `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/wqugu3/s1wwptag.php`
  - `malware-corpus/specimens/fake-plugin-ecosystem/known-families/framework-triappment/yhwb11/d5wffaqd.php`
  - `malware-corpus/specimens/fake-plugin-ecosystem/known-families/platformist-quadendpointer/3paddm/5auul2bn.php`
  - `malware-corpus/specimens/fake-plugin-ecosystem/known-families/smartrestal-serverful/1ch325/1xjh0u6z.php`
- Shared timestamp pattern across the recovered files: `2024-11-28 09:24:31`.
- Reused control flow: include secondary payload, register AJAX/bootstrap handlers, add concealment filter, add activation hooks, expose operator entry path.

Estimated overlap from normalized similarity review:

- Main plugin files: roughly 58% to 77% overlap.
- Hidden payloads:
  - `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/wqugu3/s1wwptag.php`
  - `malware-corpus/specimens/fake-plugin-ecosystem/known-families/framework-triappment/yhwb11/d5wffaqd.php`
  - `malware-corpus/specimens/fake-plugin-ecosystem/known-families/platformist-quadendpointer/3paddm/5auul2bn.php`

These three hidden payloads normalize to effectively identical code. `malware-corpus/specimens/fake-plugin-ecosystem/known-families/smartrestal-serverful/1ch325/1xjh0u6z.php` is a shorter but strongly related variant.

### Per-Plugin Roles

#### `laravel-janet`

Role: full loader/orchestrator with secret root-path trigger, AJAX bootstrap path, concealed plugin behavior, and operator login backdoor.

Key evidence:

- Main entry: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/laravel-janet.php`
- Hidden payload load: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/laravel-janet.php:123`
- Secret loader path: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/laravel-janet.php:200`
- AJAX bootstrap handlers: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/laravel-janet.php:193`
- Operator auth-cookie backdoor: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/class/quickappful-smartserveror.php:503`

#### `framework-triappment`

Role: same framework variant using an unauthenticated REST route instead of `template_redirect`.

Key evidence:

- Main entry: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/framework-triappment/framework-triappment.php`
- Hidden payload load: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/framework-triappment/framework-triappment.php:124`
- Unauthenticated REST route registration: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/framework-triappment/framework-triappment.php:217`
- Open permission callback: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/framework-triappment/framework-triappment.php:220`
- Operator auth-cookie backdoor: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/framework-triappment/bidataer-supervueable.php:82`

#### `platformist-quadendpointer`

Role: same framework variant using an unauthenticated REST route, plus shared hidden payload and operator login helper.

Key evidence:

- Main entry: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/platformist-quadendpointer/platformist-quadendpointer.php`
- Hidden payload load: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/platformist-quadendpointer/platformist-quadendpointer.php:124`
- Unauthenticated REST route registration: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/platformist-quadendpointer/platformist-quadendpointer.php:218`
- Open permission callback: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/platformist-quadendpointer/platformist-quadendpointer.php:221`
- Operator auth-cookie backdoor: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/platformist-quadendpointer/class/maxalgorithmive-multidataor.php:292`

#### `smartrestal-serverful`

Role: related loader/orchestrator variant with secret root-path trigger and login backdoor; shares the same `opertoraza.com` backend family as `laravel-janet`.

Key evidence:

- Main entry: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/smartrestal-serverful/smartrestal-serverful.php`
- Hidden payload load: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/smartrestal-serverful/smartrestal-serverful.php:124`
- Secret loader trigger creation: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/smartrestal-serverful/smartrestal-serverful.php:543`
- Operator auth-cookie backdoor: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/smartrestal-serverful/includes/ultratypescripting-smartinfrastructureible.php:437`

## Execution Flow

### `laravel-janet` Flow

1. The main plugin includes several helper files and then requires the hidden payload file in `wqugu3/s1wwptag.php`.
2. It registers:
   - public AJAX action handlers
   - a bootstrap AJAX action
   - a root-path loader via `template_redirect`
   - plugin concealment via `all_plugins`
   - activation/deactivation helpers
   - an `init` backdoor
3. The root-path loader checks for a magic GET parameter/value pair and reads `php://input`.
4. Submitted data is parsed as JSON or base64-wrapped JSON.
5. The plugin reaches out to remote C2 endpoints for configuration and click tracking.
6. It returns JavaScript that injects or refreshes staged browser code.
7. A separate AJAX bootstrap path mints a short-lived cookie used to authorize second-stage delivery.

Primary evidence:

- Hook registration: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/laravel-janet.php:181`
- Secret trigger gate: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/laravel-janet.php:220`
- Request-body processing: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/laravel-janet.php:229`
- Remote call wrapper: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/laravel-janet.php:724`
- Config API construction: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/laravel-janet.php:562`
- Click API construction: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/laravel-janet.php:532`
- Bootstrap cookie issue: `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/laravel-janet.php:1150`

### Browser Stage

The browser stage:

- posts to the plugin bootstrap action,
- receives stage data,
- injects remote JavaScript into the page,
- tags script nodes with family-specific attributes,
- stores/reuses state,
- exposes a callable window object under the plugin key.

Evidence:

- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/assets/obrw73rw.js:71`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/assets/obrw73rw.js:90`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/assets/obrw73rw.js:142`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/assets/obrw73rw.js:221`

## Operator Access / Backdoor Behavior

Each plugin includes an `init`-time routine that checks for secret GET parameters, converts one parameter to an integer user ID, and then calls `wp_set_auth_cookie()` before redirecting to `/wp-admin`.

This is not account creation. It is direct impersonation of an existing WordPress user by ID.

Confirmed parameter pairs:

| Plugin | User ID Param | Secret Param | Secret Value |
|---|---|---|---|
| `laravel-janet` | `triapplicational_unimicroservicesion` | `projavascriptsion_applicational` | `3hSf3XbHc93vvhTHlhwPEmbYUT94MBas` |
| `framework-triappment` | `microvueity_supercloudment` | `micromicroserviceor_service` | `JTMtIqkCSDDR3DnRt87r0cfs6176BQDp` |
| `platformist-quadendpointer` | `ultraapping_provueive` | `automicroserviceic_fastapplicational` | `fS1YMEltDz1Elm9NzdQs3pOHTta3ypIr` |
| `smartrestal-serverful` | `maxservice_automicroserviceist` | `nanoserviceism_quickangularor` | `MpoJjsJx8KtQfybFePKBRyFV1B1Mjkkj` |

Evidence:

- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/class/quickappful-smartserveror.php:506`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/framework-triappment/bidataer-supervueable.php:85`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/platformist-quadendpointer/class/maxalgorithmive-multidataor.php:292`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/smartrestal-serverful/includes/ultratypescripting-smartinfrastructureible.php:440`

## Network Communications

### Decoded Backend Domains

- `https://opertoraza.com`
- `https://juioprtexi.com`
- `https://kiloporotolimo.com`

Mapping:

- `laravel-janet` -> `opertoraza.com`
- `smartrestal-serverful` -> `opertoraza.com`
- `framework-triappment` -> `juioprtexi.com`
- `platformist-quadendpointer` -> `kiloporotolimo.com`

### Confirmed API Patterns

- `/api/config/<n>`
- `/api/click`

### HTTP Metadata Sent Outbound

The loaders forward:

- `User-Agent`
- `X-Forwarded-For`
- `X-Real-IP`
- `X-Forwarded-Host`
- `Referer`

Evidence:

- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/laravel-janet.php:747`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/framework-triappment/framework-triappment.php:727`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/platformist-quadendpointer/platformist-quadendpointer.php:732`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/smartrestal-serverful/smartrestal-serverful.php:617`

### External Communication Model

The malware does not just beacon once. It appears to:

- identify the visitor or target context,
- request per-target config from remote infrastructure,
- possibly track click/interaction events,
- return JavaScript to be injected into pages,
- set short-lived verification state to control second-stage delivery.

## Persistence Analysis

### Confirmed

- Plugin concealment from the WordPress plugin list.
- Operator re-entry using secret GET parameters.
- Bootstrap cookies for short-lived stage authorization.

### Not Found

- Custom WordPress cron jobs.
- Family-specific `wp_options` entries.
- User creation.
- Capability or role modification.
- MU-plugin creation.
- `activate_plugin()` or `deactivate_plugins()` persistence logic.
- Clear self-reinstallation code in the active execution chain.

### Notes

Activation/deactivation helpers shown in sampled evidence only call `flush_rewrite_rules()`.

Evidence:

- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/class/quadtypescriptism-fastbackendly.php:663`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/class/nanotypescriptible-lopez.php:223`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/class/quickserviceable-nanocloudic.php:584`
- `malware-corpus/site-artifacts/lkandersondds/cron.txt:1`

## Capability Assessment

| Capability | Assessment | Basis |
|---|---|---|
| Execute commands | Low-confidence only | Isolated `passthru('ls -la')` and `popen('ls -la')` found outside proven active path |
| Upload files | Not confirmed | No active upload handling tied to core flow |
| Download files | Confirmed | Remote config/stage retrieval via `wp_remote_request` |
| Write arbitrary files | Not confirmed in active path | Some helper/filler routines use file I/O |
| Modify WordPress settings | Not confirmed | No confirmed `update_option`/`add_option` path for family persistence |
| Create users | Not confirmed | No `wp_create_user` or `wp_insert_user` observed |
| Modify content | Not directly confirmed | Browser-stage JS injection could affect rendered pages |
| Inject JavaScript | Confirmed | Remote stage returned and inserted into DOM |
| Proxy traffic | Not confirmed | No confirmed general-purpose proxy path |
| Remote shell | Not confirmed | No active shell handler proven |
| Exfiltrate data | Confirmed | IP, host, referer, UA, and victim IDs sent outbound |

## Indicators of Compromise

### Filenames and Directories

- `laravel-janet`
- `framework-triappment`
- `platformist-quadendpointer`
- `smartrestal-serverful`
- `wqugu3/s1wwptag.php`
- `yhwb11/d5wffaqd.php`
- `3paddm/5auul2bn.php`
- `1ch325/1xjh0u6z.php`
- `assets/obrw73rw.js`
- `assets/hfpb6931.js`
- `assets/yv6b6sff.js`
- `assets/o5cn7vrg.js`

### URLs / Domains

- `https://opertoraza.com`
- `https://juioprtexi.com`
- `https://kiloporotolimo.com`
- `/api/config/<n>`
- `/api/click`

### Routes / Hooks

- `framework-triappment-xk30rc/v1/s36f8`
- `platformist-quadendpointer-sxadtr/v1/s6qgn`
- AJAX actions:
  - `quickjavascripter_megaapiity`
  - `maxendpointable_ultradataless`
  - `multiapping_fastapiity`

### Browser Indicators

- `data-ph-pid`
- `tridatation_quicktypescriptal`
- `fastreactic_nanomicroserviceing`

### SHA-256

- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/laravel-janet.php` - `9B39B460C148C7177834C48A4B1C3D7FCB7C3A7BE9A4984857E8140B41E8FA17`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/framework-triappment/framework-triappment.php` - `94CB502F90FB51F152EC85557FED6E8AD90B70544496BF478C06625A367E114E`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/platformist-quadendpointer/platformist-quadendpointer.php` - `3B748449290AD21806C864139A5583A8A0F1FB39D99F01CFD67568796B6081B4`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/smartrestal-serverful/smartrestal-serverful.php` - `B0F24ED45B2F9048AE1E14065F30CB1C534786ACC81DAC74FA98F0917F783341`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/laravel-janet/wqugu3/s1wwptag.php` - `D6213E5FF2903B65DC52C35749F74ABCD2437A432528A11078E25095E7949903`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/framework-triappment/yhwb11/d5wffaqd.php` - `5C89B40B7C22112CEC4718BAAA9E6687869F7CDF1CCC89F3846863BC1D53914A`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/platformist-quadendpointer/3paddm/5auul2bn.php` - `CD13A26D7E26C788A6B0C2304196BC6A9794728AF2334DE0DD2B67CFA283F536`
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/smartrestal-serverful/1ch325/1xjh0u6z.php` - `D8FDFFD8074B2E371D479E2E56D0F91E89BABAC8AADBBE5118CA5C62D2AA3344`

## Detection Guidance

### YARA-Style Logic

Trigger on files containing `wp_set_auth_cookie(` plus at least two of:

- `data-ph-pid`
- `permission_callback' => '__return_true'`
- `/api/config/`
- `/api/click`
- `fastreactic_nanomicroserviceing`
- `framework-triappment-xk30rc/v1`
- `platformist-quadendpointer-sxadtr/v1`
- `opertoraza.com`
- `juioprtexi.com`
- `kiloporotolimo.com`

### WordPress Fleet Search

Recommended searches:

```powershell
rg -n "wp_set_auth_cookie\(|permission_callback' => '__return_true'|/api/config/|/api/click|data-ph-pid|fastreactic_nanomicroserviceing" wp-content\plugins
```

```powershell
rg -n "register_activation_hook\(__FILE__|add_filter\('all_plugins'|wp_ajax_nopriv_|register_rest_route" wp-content\plugins
```

### Filename Heuristics

Flag plugin trees with all of the following:

- fake plugin metadata and nonsense naming,
- a short random subdirectory name,
- an 8-character PHP file inside that subdirectory,
- a short random `.js` asset file in the plugin root or assets directory.

## Remediation Guidance

- Remove all four plugin trees together.
- Rotate WordPress salts immediately.
- Reset admin credentials and review admin users for abuse of existing accounts.
- Review access logs for the secret GET parameters and REST routes listed above.
- Block egress to the decoded domains and search proxy/DNS logs for those hostnames and `/api/config/` or `/api/click`.
- Investigate the initial intrusion vector separately. This family does not appear to contain strong self-reinstallation or `wp_options` persistence, so reinfection likely depends on the original foothold.

## Bottom Line

This malware family is best understood as a reusable WordPress access and payload-delivery framework. Its most important confirmed behaviors are:

- hidden plugin-based persistence through concealment,
- direct operator login as existing users,
- remote configuration retrieval,
- staged JavaScript injection,
- shared builder-generated code reused across multiple fake plugins.
