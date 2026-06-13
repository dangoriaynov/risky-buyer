"""
riskybuyer.com — central shared blacklist API.

Open read (GET), authenticated write (POST/DELETE) gated by an API key whose
scope is 'write'. The server stamps each entry with the key's site_domain, so a
client cannot forge ownership; updates/deletes only affect the owner's entries.

Static site (landing, Terms, Privacy) is served from app/static.
"""

import os
import re
import sqlite3
import hashlib
import secrets
import datetime
from typing import List, Optional

from fastapi import FastAPI, Header, HTTPException
from fastapi.responses import FileResponse
from pydantic import BaseModel

DB_PATH = os.environ.get("RB_DB", "/var/lib/riskybuyer/riskybuyer.db")
STATIC_DIR = os.path.join(os.path.dirname(__file__), "static")

app = FastAPI(title="riskybuyer", docs_url=None, redoc_url=None, openapi_url=None)


# --------------------------------------------------------------------------- #
# DB                                                                          #
# --------------------------------------------------------------------------- #

def db() -> sqlite3.Connection:
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn


def init_db() -> None:
    os.makedirs(os.path.dirname(DB_PATH), exist_ok=True)
    conn = db()
    conn.executescript(
        """
        CREATE TABLE IF NOT EXISTS entries (
            uuid TEXT PRIMARY KEY,
            phone_norm TEXT DEFAULT '',
            phone_raw TEXT DEFAULT '',
            name_norm TEXT DEFAULT '',
            name_raw TEXT DEFAULT '',
            reason TEXT DEFAULT 'other',
            note TEXT DEFAULT '',
            source_site TEXT DEFAULT '',
            status TEXT DEFAULT 'active',
            updated_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_entries_phone ON entries(phone_norm);
        CREATE INDEX IF NOT EXISTS idx_entries_updated ON entries(updated_at);
        CREATE TABLE IF NOT EXISTS api_keys (
            key_hash TEXT PRIMARY KEY,
            site_domain TEXT,
            scope TEXT DEFAULT 'read',
            created_at TEXT
        );
        """
    )
    conn.commit()
    conn.close()


init_db()


# --------------------------------------------------------------------------- #
# Helpers (normalization mirrors the WordPress plugin)                        #
# --------------------------------------------------------------------------- #

_NAME_RE = re.compile(r"[\W_]+", re.UNICODE)
_VALID_REASONS = {"uncollected", "fake", "abusive", "other"}


def norm_phone(s: Optional[str]) -> str:
    digits = re.sub(r"\D+", "", s or "")
    return digits[-9:] if len(digits) >= 9 else ""


def norm_name(s: Optional[str]) -> str:
    s = (s or "").lower()
    s = _NAME_RE.sub(" ", s)
    s = re.sub(r"\s+", " ", s).strip()
    return s if len(s) >= 3 else ""


def valid_reason(r: Optional[str]) -> str:
    return r if r in _VALID_REASONS else "other"


def now_iso() -> str:
    return datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def require_write(authorization: Optional[str]) -> sqlite3.Row:
    if not authorization or not authorization.lower().startswith("bearer "):
        raise HTTPException(status_code=401, detail="Missing API key")
    key = authorization.split(" ", 1)[1].strip()
    key_hash = hashlib.sha256(key.encode("utf-8")).hexdigest()
    conn = db()
    row = conn.execute("SELECT * FROM api_keys WHERE key_hash = ?", (key_hash,)).fetchone()
    conn.close()
    if not row:
        raise HTTPException(status_code=403, detail="Invalid API key")
    if row["scope"] != "write":
        raise HTTPException(status_code=403, detail="Key is not authorized to write")
    return row


# --------------------------------------------------------------------------- #
# Schemas                                                                     #
# --------------------------------------------------------------------------- #

class EntryIn(BaseModel):
    uuid: Optional[str] = None
    phone: Optional[str] = ""
    name: Optional[str] = ""
    reason: Optional[str] = "other"
    note: Optional[str] = ""
    status: Optional[str] = "active"


class EntriesIn(BaseModel):
    entries: List[EntryIn]


# --------------------------------------------------------------------------- #
# API                                                                         #
# --------------------------------------------------------------------------- #

@app.get("/healthz")
def healthz():
    return {"ok": True}


@app.get("/v1/entries")
def list_entries(since: Optional[str] = None):
    conn = db()
    if since:
        rows = conn.execute(
            "SELECT uuid, phone_norm, phone_raw, name_norm, name_raw, reason, note, "
            "source_site, status, updated_at FROM entries WHERE updated_at > ? "
            "ORDER BY updated_at ASC LIMIT 10000",
            (since,),
        ).fetchall()
    else:
        rows = conn.execute(
            "SELECT uuid, phone_norm, phone_raw, name_norm, name_raw, reason, note, "
            "source_site, status, updated_at FROM entries WHERE status = 'active' "
            "ORDER BY updated_at DESC LIMIT 10000"
        ).fetchall()
    conn.close()
    return {"entries": [dict(r) for r in rows], "now": now_iso()}


@app.post("/v1/entries")
def upsert_entries(payload: EntriesIn, authorization: Optional[str] = Header(default=None)):
    key = require_write(authorization)
    domain = key["site_domain"]
    conn = db()
    upserted = 0
    for e in payload.entries:
        uuid = (e.uuid or "").strip() or secrets.token_hex(16)
        row = {
            "uuid": uuid,
            "phone_norm": norm_phone(e.phone),
            "phone_raw": (e.phone or "")[:64],
            "name_norm": norm_name(e.name),
            "name_raw": (e.name or "")[:191],
            "reason": valid_reason(e.reason),
            "note": (e.note or "")[:1000],
            "source_site": domain,
            "status": e.status if e.status in ("active", "removed") else "active",
            "updated_at": now_iso(),
        }
        if not row["phone_norm"] and not row["name_norm"]:
            continue
        # Insert new; on uuid conflict, update ONLY if this key owns the row.
        conn.execute(
            """
            INSERT INTO entries (uuid, phone_norm, phone_raw, name_norm, name_raw,
                                 reason, note, source_site, status, updated_at)
            VALUES (:uuid, :phone_norm, :phone_raw, :name_norm, :name_raw,
                    :reason, :note, :source_site, :status, :updated_at)
            ON CONFLICT(uuid) DO UPDATE SET
                phone_norm = excluded.phone_norm, phone_raw = excluded.phone_raw,
                name_norm = excluded.name_norm, name_raw = excluded.name_raw,
                reason = excluded.reason, note = excluded.note,
                status = excluded.status, updated_at = excluded.updated_at
            WHERE entries.source_site = :source_site
            """,
            row,
        )
        upserted += 1
    conn.commit()
    conn.close()
    return {"ok": True, "upserted": upserted}


@app.delete("/v1/entries/{uuid}")
def delete_entry(uuid: str, authorization: Optional[str] = Header(default=None)):
    key = require_write(authorization)
    conn = db()
    conn.execute(
        "UPDATE entries SET status = 'removed', updated_at = ? "
        "WHERE uuid = ? AND source_site = ?",
        (now_iso(), uuid, key["site_domain"]),
    )
    conn.commit()
    conn.close()
    return {"ok": True}


# --------------------------------------------------------------------------- #
# Static site                                                                 #
# --------------------------------------------------------------------------- #

@app.get("/")
def index():
    return FileResponse(os.path.join(STATIC_DIR, "index.html"))


@app.get("/terms")
def terms():
    return FileResponse(os.path.join(STATIC_DIR, "terms.html"))


@app.get("/privacy")
def privacy():
    return FileResponse(os.path.join(STATIC_DIR, "privacy.html"))
