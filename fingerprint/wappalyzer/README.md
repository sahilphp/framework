# SahilPHP — Wappalyzer / WebAppAnalyzer fingerprint

Contribution-ready fingerprint definition for
[WebAppAnalyzer](https://github.com/enthec/webappanalyzer), the maintained
open-source continuation of the Wappalyzer technology database.

## Contents

| File | Purpose |
| --- | --- |
| `sahilphp.json` | The `SahilPHP` technology block, ready to merge into `src/technologies/s.json`. |
| `icons/SahilPHP.png` | The official SahilPHP mark (from the starter kit's `public/`), for `src/images/icons/`. |
| `schema.json` | Vendored copy of the upstream schema, used by `tests/Identification/WappalyzerFingerprintTest.php` for offline validation. |
| `categories.json` | Vendored upstream category catalogue; the entry uses category `18` ("Web frameworks"). |

## Signals

The fingerprint relies on stable public conventions the framework itself
produces — nothing artificial was added for detection:

- `meta.generator` — `<meta name="generator" content="SahilPHP[ x.y.z]">`
  injected into every framework-produced HTML document; the version
  capture feeds Wappalyzer's `version:` extraction.
- `headers.X-Powered-By` — `X-Powered-By: SahilPHP`, sent when
  `identification.powered_by` is enabled (the default).
- `cookies.sahil_session` — the framework's default session cookie name.
- `implies: PHP` — SahilPHP is a PHP framework.

## Upstream submission steps (manual — requires real adoption evidence)

Per `CONTRIBUTING.md` in `enthec/webappanalyzer`:

1. Merge the `SahilPHP` block into `src/technologies/s.json` (alphabetical
   order is enforced by `scripts/fix_order.py`).
2. Copy `icons/SahilPHP.png` into `src/images/icons/`.
3. Run `python3 scripts/fix_order.py`, then `python3 scripts/check.py`
   (install `.github/workflows/requirements.txt` first).
4. **Open the pull request with ten or more links to live websites running
   SahilPHP.** This is a hard upstream requirement and is the one step that
   cannot be done inside this repository: the list must come from real
   deployments. Collect them before submitting.
5. Keep `AGENTS.md` rules in mind: every pattern must be verified with a
   source, no generic patterns, `implies`/`requires` only for real
   dependencies.

The local schema validation (`composer test --
--filter WappalyzerFingerprintTest`) covers the JSON structure only; it is
not a substitute for upstream review and does not imply approval.
