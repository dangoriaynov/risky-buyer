"""Tiny management CLI.

Usage (from the server/ directory):
    python -m app.manage create-key <site_domain> <scope:read|write>
    python -m app.manage list-keys
"""

import sys
import secrets
import hashlib

from app.main import db, init_db, now_iso


def create_key(domain: str, scope: str = "read") -> None:
    scope = "write" if scope == "write" else "read"
    init_db()
    key = secrets.token_urlsafe(32)
    key_hash = hashlib.sha256(key.encode("utf-8")).hexdigest()
    conn = db()
    conn.execute(
        "INSERT OR REPLACE INTO api_keys (key_hash, site_domain, scope, created_at) "
        "VALUES (?, ?, ?, ?)",
        (key_hash, domain, scope, now_iso()),
    )
    conn.commit()
    conn.close()
    print("API key for %s (scope=%s):" % (domain, scope))
    print(key)
    print("(store it now — only the SHA-256 hash is kept on the server)")


def list_keys() -> None:
    init_db()
    conn = db()
    for r in conn.execute("SELECT site_domain, scope, created_at FROM api_keys ORDER BY created_at"):
        print("%s\t%s\t%s" % (r["site_domain"], r["scope"], r["created_at"]))
    conn.close()


def list_appeals() -> None:
    init_db()
    conn = db()
    for r in conn.execute( "SELECT id, created_at, status, name, phone, message FROM appeals ORDER BY created_at DESC LIMIT 200" ):
        print( "#%d [%s] %s | %s | %s | %s" % ( r["id"], r["status"], r["created_at"], r["name"], r["phone"], ( r["message"] or "" )[:120] ) )
    conn.close()


if __name__ == "__main__":
    if len(sys.argv) >= 2 and sys.argv[1] == "appeals":
        list_appeals()
        sys.exit(0)
    if len(sys.argv) >= 3 and sys.argv[1] == "create-key":
        create_key(sys.argv[2], sys.argv[3] if len(sys.argv) > 3 else "read")
    elif len(sys.argv) >= 2 and sys.argv[1] == "list-keys":
        list_keys()
    else:
        print("usage: python -m app.manage create-key <site_domain> <scope:read|write>")
        print("       python -m app.manage list-keys")
