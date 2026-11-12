"""The central API through its HTTP surface (no network, a throwaway database)."""
import importlib
import io
import contextlib
import os
import sys
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))


@pytest.fixture
def api(tmp_path, monkeypatch):
    """A fresh app on its own database; api.key(scope) mints a key the way the CLI does."""
    monkeypatch.setenv("RB_DB", str(tmp_path / "rb.db"))
    monkeypatch.delenv("RB_READ_NEEDS_KEY", raising=False)
    for name in ("app.main", "app.manage"):
        sys.modules.pop(name, None)
    main = importlib.import_module("app.main")
    manage = importlib.import_module("app.manage")
    client = TestClient(main.app)

    def key(scope="write", domain="shop.example"):
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            manage.create_key(domain, scope)
        return {"Authorization": "Bearer " + out.getvalue().splitlines()[1].strip()}

    client.key, client.main = key, main
    return client


ENTRY = {"uuid": "11111111-2222-3333-4444-555555555555", "phone": "+359 888 123 456", "name": "Ivan Ivanov"}


def test_only_a_write_key_adds_entries_and_only_the_owner_changes_them(api):
    assert api.post("/v1/entries", json={"entries": [ENTRY]}).status_code == 401
    assert api.post("/v1/entries", json={"entries": [ENTRY]}, headers=api.key("read")).status_code == 403
    owner, other = api.key("write", "a.example"), api.key("write", "b.example")
    assert api.post("/v1/entries", json={"entries": [ENTRY]}, headers=owner).json()["upserted"] == 1
    api.post("/v1/entries", json={"entries": [dict(ENTRY, name="Someone Else")]}, headers=other)
    api.delete("/v1/entries/" + ENTRY["uuid"], headers=other)
    listed = api.get("/v1/entries").json()["entries"]
    assert [(e["name_raw"], e["source_site"], e["status"]) for e in listed] == [("Ivan Ivanov", "a.example", "active")]


def test_reading_the_list_can_be_made_to_need_a_key(api, monkeypatch):
    api.post("/v1/entries", json={"entries": [ENTRY]}, headers=api.key())
    assert api.get("/v1/entries").status_code == 200
    monkeypatch.setattr(api.main, "READ_NEEDS_KEY", True)
    assert api.get("/v1/entries").status_code == 401
    assert api.get("/v1/entries", headers={"Authorization": "Bearer wrong"}).status_code == 403
    assert len(api.get("/v1/entries", headers=api.key("read")).json()["entries"]) == 1


@pytest.mark.parametrize("uuid", [
    pytest.param("x" * 65, id="too long"),
    pytest.param("short", id="too short"),
    pytest.param("../../etc/passwd", id="not an id"),
    pytest.param("<script>alert(1)</script>", id="markup"),
])
def test_an_entry_with_a_made_up_id_is_not_stored(api, uuid):
    r = api.post("/v1/entries", json={"entries": [dict(ENTRY, uuid=uuid)]}, headers=api.key())
    assert r.json()["upserted"] == 0 and api.get("/v1/entries").json()["entries"] == []


def test_one_push_is_bounded(api):
    many = [dict(ENTRY, uuid="%036d" % i) for i in range(api.main.MAX_ENTRIES + 1)]
    assert api.post("/v1/entries", json={"entries": many}, headers=api.key()).status_code == 422
    assert api.post("/v1/entries", json={"entries": many[:-1]}, headers=api.key()).json()["upserted"] == api.main.MAX_ENTRIES


def test_an_oversized_body_is_refused_before_it_is_parsed(api):
    big = "x" * (api.main.MAX_BODY_BYTES + 1)
    r = api.post("/v1/appeal", content=big, headers={"Content-Type": "application/json"})
    assert r.status_code == 413


def test_the_appeal_form_runs_out_per_visitor(api):
    per_ip = api.main.APPEALS_PER_IP[0]
    codes = [api.post("/v1/appeal", json={"message": "please remove me"}).status_code for _ in range(per_ip + 1)]
    assert codes == [200] * per_ip + [429]
    # the honeypot path stores nothing and spends nothing
    assert api.post("/v1/appeal", json={"message": "x", "website": "spam"}).status_code == 200


def test_the_appeal_form_runs_out_for_everyone_together(api, monkeypatch):
    monkeypatch.setattr(api.main, "APPEALS_TOTAL", (2, 86400))
    monkeypatch.setattr(api.main, "APPEALS_PER_IP", (100, 3600))
    assert [api.post("/v1/appeal", json={"message": "m"}).status_code for _ in range(3)] == [200, 200, 429]


@pytest.mark.parametrize("path", ["/", "/terms", "/v1/entries", "/healthz", "/missing"])
def test_every_answer_carries_the_browser_policy(api, path):
    h = api.get(path).headers
    assert h["x-content-type-options"] == "nosniff" and h["x-frame-options"] == "DENY"
    assert "frame-ancestors 'none'" in h["content-security-policy"]


@pytest.mark.parametrize("path", ["/docs", "/redoc", "/openapi.json"])
def test_no_api_docs(api, path):
    assert api.get(path).status_code == 404


def test_the_unit_trusts_forwarded_headers_from_the_proxy_only():
    unit = (Path(__file__).resolve().parent.parent / "deploy" / "risky-buyer.service").read_text()
    assert "--forwarded-allow-ips=*" not in unit


def test_the_unit_matches_the_layout_of_the_host():
    unit = (Path(__file__).resolve().parent.parent / "deploy" / "risky-buyer.service").read_text()
    for line in ("User=risky-buyer", "WorkingDirectory=/opt/risky-buyer/server",
                 "EnvironmentFile=-/etc/risky-buyer/risky-buyer.env", "ReadWritePaths=/var/lib/riskybuyer"):
        assert line in unit.splitlines()


def test_appeals_are_printed_without_control_characters(api, capsys):
    api.post("/v1/appeal", json={"name": "A\x1b[2JB", "message": "line\r\nnext"})
    importlib.import_module("app.manage").list_appeals()
    out = capsys.readouterr().out
    assert "\x1b" not in out and "\r" not in out and "A?[2JB" in out
