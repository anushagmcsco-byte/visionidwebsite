# VisionID Industry Pulse: collector

Builds `news.json` for the **Industry Pulse** section on the website's Insights page.

## What it publishes
| Type | Source | How |
|---|---|---|
| Service status | Providers' public status pages (`config.json → status_sources`) | Automatic, major/critical incidents only |
| Vulnerabilities | NIST NVD API 2.0 | Automatic, CVSS ≥ 9.0 and matching the watch list |
| Best practices | `practices.json` (CERT-In / NIST guidance) | Edited by your team |

All text comes from fixed, neutral templates. The collector never copies vendor statements or news articles and never guesses causes. Providers are **anonymous by default** ("Code hosting platform"). Set `"naming": "named"` to show provider names.

## Setup (on the web server)
1. Requires Python 3.9+. No packages to install.
2. Copy this folder next to the website. In `config.json`, point `output` at the site's `news.json`. It must be served from the same origin as `index.html`.
3. Optional but recommended: get a free NVD API key at https://nvd.nist.gov/developers/request-an-api-key and set `export NVD_API_KEY=...`.
4. Check each status URL in `config.json` once in a browser. Providers sometimes change them.
5. Schedule it, for example with cron every 20 minutes:
   ```
   */20 * * * * cd /opt/visionid/pulse && /usr/bin/python3 pulse.py collect >> pulse.log 2>&1
   ```

## Daily approval (about 1 minute)
```
python3 pulse.py list              # see what's waiting
python3 pulse.py approve ID ID     # publish selected items
python3 pulse.py approve --all     # publish everything waiting
python3 pulse.py reject ID         # never show this item
```
Once approved, an item's status (Investigating → Recovering → Resolved) updates automatically on later runs. The text comes from fixed templates, so these updates are safe.
Set `"require_approval": false` to publish without review. This is not recommended.

## Expiry
Service items disappear after 72 hours and vulnerabilities after 30 days (`max_age_hours`). Best practices stay until you remove them from `practices.json`.

## Adding a CERT-In advisory or best practice
CERT-In has no stable machine-readable feed, so add advisories by hand to `practices.json`:
```json
{ "id": "certin-2026-xx", "type": "practice", "title": "…", "summary": "…",
  "action": "…", "date": "2026-11-14", "ref": "CERT-In Advisory CIAD-2026-XXXX",
  "source": "CERT-In", "url": "https://www.cert-in.org.in/" }
```
Then run `python3 pulse.py publish`.
