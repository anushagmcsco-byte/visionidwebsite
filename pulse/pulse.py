#!/usr/bin/env python3
"""
VisionID Industry Pulse collector
=================================
Builds the website's `news.json` from official public sources only:

  * Service status  - provider status pages (Atlassian Statuspage API, Google status JSON, RSS)
  * Vulnerabilities - NIST National Vulnerability Database (NVD) API 2.0, critical CVEs on a watch list
  * Best practices  - curated `practices.json` (CERT-In / NIST guidance, maintained by your team)

Every item is written with fixed, neutral templates (no vendor blame, no speculation).
New items wait in a pending queue until a person approves them (configurable).

Usage
-----
  python3 pulse.py collect            # fetch sources, queue new items, refresh approved ones, publish
  python3 pulse.py list               # show items waiting for approval
  python3 pulse.py approve ID [ID..]  # approve items (or --all)
  python3 pulse.py reject  ID [ID..]  # drop items
  python3 pulse.py publish            # rebuild news.json from approved items + practices

Standard library only. Python 3.9+.
"""
from __future__ import annotations

import html
import json
import os
import re
import sys
import tempfile
import urllib.request
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Iterable

HERE = Path(__file__).resolve().parent
CONFIG: dict[str, Any] = json.loads((HERE / "config.json").read_text(encoding="utf-8"))
STATE = (HERE / CONFIG.get("state_dir", "data")).resolve()
STATE.mkdir(parents=True, exist_ok=True)
OUTPUT = (HERE / CONFIG["output"]).resolve()
IST = timezone(timedelta(hours=5, minutes=30))
MAX_BYTES = 3 * 1024 * 1024
UA = "VisionID-IndustryPulse/1.0 (+https://visionid.in)"

# ---------------------------------------------------------------- helpers
def now() -> datetime:
    return datetime.now(timezone.utc)


def log(msg: str) -> None:
    print(f"[{now().astimezone(IST):%Y-%m-%d %H:%M:%S IST}] {msg}", flush=True)


def fetch(url: str, headers: dict[str, str] | None = None) -> bytes:
    """HTTPS-only GET with timeout and size cap."""
    if not url.startswith("https://"):
        raise ValueError(f"refusing non-HTTPS url: {url}")
    req = urllib.request.Request(url, headers={"User-Agent": UA, **(headers or {})})
    with urllib.request.urlopen(req, timeout=20) as r:  # noqa: S310 (https enforced above)
        data = r.read(MAX_BYTES + 1)
    if len(data) > MAX_BYTES:
        raise ValueError(f"response too large: {url}")
    return data


def plain(text: Any, limit: int = 300) -> str:
    """Strip HTML, collapse whitespace, truncate on a word boundary."""
    s = html.unescape(re.sub(r"<[^>]+>", " ", str(text or "")))
    s = re.sub(r"\s+", " ", s).strip()
    if len(s) > limit:
        s = s[:limit].rsplit(" ", 1)[0].rstrip(",.;:") + "…"
    return s


def load(name: str, default: Any) -> Any:
    p = STATE / name
    return json.loads(p.read_text(encoding="utf-8")) if p.exists() else default


def save_json(path: Path, data: Any) -> None:
    """Atomic write so the website never reads a half-written file."""
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(dir=path.parent, prefix=".tmp-", suffix=".json")
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    os.replace(tmp, path)


def iso_date(value: str | None) -> str:
    try:
        return datetime.fromisoformat(str(value).replace("Z", "+00:00")).date().isoformat()
    except ValueError:
        return now().date().isoformat()


def who(src: dict[str, str]) -> str:
    """Named or anonymous provider label, per config."""
    return src["name"] if CONFIG.get("naming") == "named" else src["label"]


# ---------------------------------------------------------------- neutral templates
STATUS_LABEL = {"investigating": "Investigating", "identified": "Identified",
                "monitoring": "Recovering", "resolved": "Resolved"}
STATUS_TEXT = {
    "investigating": "Some users may see errors or delays. The provider is investigating.",
    "identified": "The provider has identified the cause and is working on a fix.",
    "monitoring": "A fix has been applied and the provider is monitoring recovery.",
    "resolved": "The provider reports that the service has been restored.",
}
ACTION_ACTIVE = "Check the provider's status page before raising internal tickets, and plan around affected features."
ACTION_RESOLVED = "No action needed. If users still see issues, ask them to sign out, sign in again and retry."


def disruption(src: dict[str, str], ext_id: str, area: str, state: str, date: str, url: str) -> dict:
    state = state if state in STATUS_TEXT else "investigating"
    # Anonymous mode must not leak brand names through component/product names.
    area = plain(area, 70) if CONFIG.get("naming") == "named" else src.get("area", "service")
    area = area or "service"
    return {
        "id": f"dis-{src['key']}-{ext_id}",
        "type": "disruption",
        "title": f"{who(src)}: {area} disruption",
        "summary": STATUS_TEXT[state],
        "action": ACTION_RESOLVED if state == "resolved" else ACTION_ACTIVE,
        "status": STATUS_LABEL[state],
        "severity": "Major",
        "date": date,
        "source": "Provider's public status page" if CONFIG.get("naming") != "named" else f"{src['name']} status page",
        "url": url if url.startswith("https://") else "",
    }


# ---------------------------------------------------------------- sources: service status
def from_statuspage(src: dict[str, str]) -> Iterable[dict]:
    data = json.loads(fetch(src["url"]))
    impacts = set(CONFIG.get("min_impact", ["major", "critical"]))
    for inc in data.get("incidents", [])[:25]:
        if inc.get("impact") not in impacts:
            continue
        comps = [c.get("name", "") for c in inc.get("components") or []][:2]
        state = "resolved" if inc.get("status") in ("resolved", "postmortem") else inc.get("status", "")
        yield disruption(src, str(inc.get("id")), ", ".join(c for c in comps if c) or "service",
                         state, iso_date(inc.get("started_at") or inc.get("created_at")), inc.get("shortlink") or "")


def from_google(src: dict[str, str]) -> Iterable[dict]:
    data = json.loads(fetch(src["url"]))
    for inc in data[:25]:
        recent = (inc.get("most_recent_update") or {}).get("status", "")
        if inc.get("severity") != "high" and recent not in ("SERVICE_OUTAGE",):
            continue
        prods = [p.get("title", "") for p in inc.get("affected_products") or []][:2]
        state = "resolved" if inc.get("end") else "investigating"
        uri = inc.get("uri", "")
        url = uri if uri.startswith("https://") else (src["base"] + uri if uri else "")
        yield disruption(src, str(inc.get("id") or inc.get("number")), ", ".join(prods) or "service",
                         state, iso_date(inc.get("begin")), url)


def from_rss(src: dict[str, str]) -> Iterable[dict]:
    root = ET.fromstring(fetch(src["url"]))  # trusted HTTPS source; stdlib parser does not resolve external entities
    for it in root.iter("item"):
        title = plain(it.findtext("title"), 70)
        guid = re.sub(r"[^A-Za-z0-9]+", "-", it.findtext("guid") or title)[:60]
        pub = it.findtext("pubDate") or ""
        try:
            date = datetime.strptime(pub[:25], "%a, %d %b %Y %H:%M:%S").date().isoformat()
        except ValueError:
            date = now().date().isoformat()
        resolved = bool(re.search(r"\b(resolved|mitigated|restored)\b", (it.findtext("description") or "").lower()))
        yield disruption(src, guid, src.get("area", "service"), "resolved" if resolved else "investigating",
                         date, it.findtext("link") or "")


# ---------------------------------------------------------------- sources: NIST NVD
def from_nvd() -> Iterable[dict]:
    cfg = CONFIG.get("nvd", {})
    if not cfg.get("enabled"):
        return
    end = now()
    start = end - timedelta(days=int(cfg.get("lookback_days", 3)))
    fmt = "%Y-%m-%dT%H:%M:%S.000"
    url = ("https://services.nvd.nist.gov/rest/json/cves/2.0?cvssV3Severity=CRITICAL"
           f"&pubStartDate={start.strftime(fmt)}&pubEndDate={end.strftime(fmt)}&resultsPerPage=200")
    key = os.environ.get(cfg.get("api_key_env", "NVD_API_KEY"), "")
    data = json.loads(fetch(url, {"apiKey": key} if key else None))
    watch = {k.lower(): v for k, v in cfg.get("watch", {}).items()}
    min_score = float(cfg.get("min_cvss", 9.0))
    for v in data.get("vulnerabilities", []):
        cve = v.get("cve", {})
        desc = next((d["value"] for d in cve.get("descriptions", []) if d.get("lang") == "en"), "")
        metrics = (cve.get("metrics", {}).get("cvssMetricV31") or cve.get("metrics", {}).get("cvssMetricV40") or [{}])
        score = float(metrics[0].get("cvssData", {}).get("baseScore", 0) or 0)
        if score < min_score:
            continue
        low = desc.lower()
        match = next((label for kw, label in watch.items() if kw in low), None)
        if not match:
            continue
        cid = cve.get("id", "")
        yield {
            "id": f"vul-{cid}",
            "type": "vulnerability",
            "title": f"Critical vulnerability affecting {match}",
            "summary": plain(desc.split(". ")[0], 220),
            "action": "Check whether you use the affected product, apply the vendor's fix or mitigation, "
                      "and follow any related CERT-In advisory.",
            "status": "Check vendor advisory",
            "severity": "Critical",
            "cvss": round(score, 1),
            "ref": cid,
            "date": iso_date(cve.get("published")),
            "source": "NIST NVD",
            "url": f"https://nvd.nist.gov/vuln/detail/{cid}",
        }


# ---------------------------------------------------------------- commands
def collect() -> None:
    pending: dict[str, dict] = load("pending.json", {})
    approved: dict[str, dict] = load("approved.json", {})
    rejected: set[str] = set(load("rejected.json", []))
    found: list[dict] = []
    readers = {"statuspage": from_statuspage, "google": from_google, "rss": from_rss}
    for src in CONFIG.get("status_sources", []):
        try:
            found.extend(readers[src["kind"]](src))
        except Exception as e:  # one bad source must not stop the run
            log(f"WARN {src.get('key')}: {e}")
    try:
        found.extend(from_nvd())
    except Exception as e:
        log(f"WARN nvd: {e}")

    auto = not CONFIG.get("require_approval", True)
    new = updated = 0
    for item in found:
        iid = item["id"]
        if iid in rejected:
            continue
        if iid in approved:                      # template text is fixed, so status refresh is safe
            if approved[iid] != item:
                approved[iid] = item
                updated += 1
        elif auto:
            approved[iid] = item
            new += 1
        elif iid not in pending or pending[iid] != item:
            pending[iid] = item
            new += 1
    save_json(STATE / "pending.json", pending)
    save_json(STATE / "approved.json", approved)
    log(f"collected {len(found)} item(s): {new} new/changed, {updated} refreshed, {len(pending)} awaiting approval")
    publish()


def list_pending() -> None:
    pending = load("pending.json", {})
    if not pending:
        print("Nothing waiting for approval.")
    for iid, it in pending.items():
        print(f"{iid}\n   [{it['type']}] {it['title']}\n   {it['summary']}\n   {it.get('url', '')}\n")


def approve(ids: list[str]) -> None:
    pending, approved = load("pending.json", {}), load("approved.json", {})
    for iid in (list(pending) if ids == ["--all"] else ids):
        if iid in pending:
            approved[iid] = pending.pop(iid)
            log(f"approved {iid}")
        else:
            log(f"not found: {iid}")
    save_json(STATE / "pending.json", pending)
    save_json(STATE / "approved.json", approved)
    publish()


def reject(ids: list[str]) -> None:
    pending, approved = load("pending.json", {}), load("approved.json", {})
    rejected = set(load("rejected.json", []))
    for iid in ids:
        pending.pop(iid, None)
        approved.pop(iid, None)
        rejected.add(iid)
        log(f"rejected {iid}")
    save_json(STATE / "pending.json", pending)
    save_json(STATE / "approved.json", approved)
    save_json(STATE / "rejected.json", sorted(rejected))
    publish()


def publish() -> None:
    approved: dict[str, dict] = load("approved.json", {})
    max_age = CONFIG.get("max_age_hours", {"disruption": 72, "vulnerability": 720})
    cutoff = {t: now() - timedelta(hours=h) for t, h in max_age.items()}
    items = []
    for it in approved.values():
        when = datetime.fromisoformat(it["date"]).replace(tzinfo=timezone.utc)
        if it["type"] in cutoff and when < cutoff[it["type"]]:
            continue
        items.append(it)
    practices_file = HERE / "practices.json"
    if practices_file.exists():
        items.extend(json.loads(practices_file.read_text(encoding="utf-8")))
    order = {"disruption": 0, "vulnerability": 1, "practice": 2}
    items.sort(key=lambda i: (order.get(i["type"], 9), "".join(chr(255 - ord(c)) for c in i.get("date", ""))))
    save_json(OUTPUT, {"version": 1, "updated": now().astimezone(IST).isoformat(timespec="seconds"), "items": items})
    log(f"published {len(items)} item(s) to {OUTPUT}")


def main(argv: list[str]) -> None:
    cmd, args = (argv[1] if len(argv) > 1 else "help"), argv[2:]
    if cmd == "collect":
        collect()
    elif cmd == "list":
        list_pending()
    elif cmd == "approve" and args:
        approve(args)
    elif cmd == "reject" and args:
        reject(args)
    elif cmd == "publish":
        publish()
    else:
        print(__doc__)


if __name__ == "__main__":
    main(sys.argv)
