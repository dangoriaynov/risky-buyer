.PHONY: check

# No CI: run before a PR.
check:
	python3 -m venv .venv
	.venv/bin/pip install -q -r requirements-dev.txt -c constraints.txt
	.venv/bin/python -m pytest -q tests
