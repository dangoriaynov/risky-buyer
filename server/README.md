# RiskyBuyer server

Central shared-blacklist API + site for the Problem Client WooCommerce plugin.
Lightweight **FastAPI** app with **SQLite**, served behind the homelab
`nginx-proxy` for `riskybuyer.com`.

- Read: `GET /v1/entries?since=<ts>`. Open by default; with `RB_READ_NEEDS_KEY=1` in the unit it needs a
  valid key of any scope (the list holds names and phone numbers; the plugin sends its key on reads).
- Limits: 500 entries and 1 MB per push; the appeal form is rate-limited per visitor and overall.
- Authenticated write: `POST /v1/entries`, `DELETE /v1/entries/{uuid}` —
  requires `Authorization: Bearer <key>` whose scope is `write`. The server
  stamps `source_site` from the key, and updates/deletes only affect that
  owner's entries (so only authorized domains, e.g. dobavki.club, can write).
- Site: `/` (landing), `/terms`, `/privacy`.

## Layout
```
app/main.py        FastAPI app, DB, auth, endpoints, static
app/manage.py      CLI: create-key / list-keys
app/static/        index.html, terms.html, privacy.html
deploy/riskybuyer.service   systemd unit (uvicorn on :8080)
deploy/nginx-riskybuyer.conf nginx-proxy vhost template
requirements.txt
tests/             pytest (`make check` in server/)
```

## Deploy (LXC container on the homelab)
```bash
# 1) container
lxc launch images:ubuntu/24.04 riskybuyer
# 2) inside: python + venv
lxc exec risky-buyer -- bash -lc 'apt-get update && apt-get install -y python3-venv'
lxc exec risky-buyer -- useradd --system --create-home --home-dir /opt/riskybuyer riskybuyer
# 3) copy app to /opt/riskybuyer/server, create venv, pip install -r requirements.txt
# 4) install deploy/riskybuyer.service, enable + start
# 5) on nginx-proxy: add deploy/nginx-riskybuyer.conf (set __APP_IP__), certbot --nginx
```

## Create an API key
```bash
cd /opt/riskybuyer/server
/opt/riskybuyer/venv/bin/python -m app.manage create-key dobavki.club write
/opt/riskybuyer/venv/bin/python -m app.manage create-key example.com read   # (read is open anyway)
```

## DNS
`riskybuyer.com` + `www` → A `79.100.220.106` (homelab public IP) on Cloudflare.
Use DNS-only (grey cloud) during first certbot issuance, then proxied is fine.
