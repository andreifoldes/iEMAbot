---
title: "Security audit"
sidebar_label: "Security audit"
description: "How CI keeps private details out of the public repository, its history and this documentation site."
---

# Security audit

<span className="status status--shipped">Shipped</span>

The repository and this site are **public**, but the project is operated on a real server by real people.
The security audit makes sure operational details do not leak into either.

## What is checked

| Check | Where it runs | Catches |
| --- | --- | --- |
| **gitleaks** | `security-audit.yml` | Credential-shaped secrets across the **entire git history** (pinned, checksum-verified binary; output redacted) |
| **Tracked files** | `security-audit.yml` | Private/Tailscale IPs, home-directory paths, personal emails, password notes, `ssh` commands with an explicit user, token shapes, PEM keys |
| **Forbidden paths** | `security-audit.yml` | Tracked AI session logs, `.mcp.json`, `.env*`, key material, `settings.json` under `.claude/`, `*_secret*` / `*_token*` files, databases, `esmira_data/` |
| **History** | `security-audit.yml` | The same content rules over every **added line in every commit of every ref**. A leak that was later deleted is still public |
| **Built docs site** | `docs.yml` | The same content rules over the generated HTML, **before** it is uploaded to Pages |
| **Literals** | both | Terms from the `PRIVATE_TERMS` secret (see below) |

It runs on every push to `main`, every pull request, weekly (Monday 05:17 UTC) and on demand. The weekly run
catches rule updates and anything that slipped past a skipped check.

## Design principles

1. **Rules are classes, not values.** `scripts/security/private-patterns.json` contains regexes such as "an
   IPv4 in the Tailscale/CGNAT block", never a concrete address, username or hostname. The rule file is public, so it
   must not itself leak what it protects.
2. **Findings never echo the match.** Output shows the rule, file, line and the *length* of the redacted
   match, with no surrounding text, so CI logs cannot become a leak.
3. **Literals live in a secret.** Specific private values go in the `PRIVATE_TERMS` repository secret.
4. **Fail closed, with visible exceptions.** Accepted findings must be listed in
   `scripts/security/allowlist.json` with a reason.
5. **The scanner is tested.** `scan-private-details.test.mjs` asserts that every rule fires on a positive
   sample and stays quiet on a negative one, and fails if a rule has no test.

## Setting up `PRIVATE_TERMS`

Add a repository secret named `PRIVATE_TERMS`: **one literal per line**, matched case-insensitively
(minimum 3 characters). Good candidates:

- your operating-system or server username,
- internal hostnames and SSH aliases,
- VPN/overlay addresses,
- study access keys and participant ID prefixes,
- paths and project names that identify the host.

```bash
gh secret set PRIVATE_TERMS < private-terms.txt   # keep this file OUT of the repo
```

GitHub does not expose secrets to pull requests from forks, so for those runs only the regex rules apply.

## Pending disclosures

`allowlist.json` entries can be marked `"pending": true`. They are **real disclosures that are tolerated only
until a human decides** (remove, rewrite history, or accept). They never fail the run, but each run re-prints
them as a warning annotation, so they cannot be forgotten.

At the time of writing there are three pending items:

| Item | Detail | Decision needed |
| --- | --- | --- |
| Deploy script path | `deploy.sh` hard-codes a real user's home directory as the remote directory | Parameterise via an environment variable, or accept |
| AI session logs in the tree | Several `.claude/logs/*` files are still tracked despite `.gitignore` | Remove from the tree; review contents |
| AI session logs in history | The same files, and the home path inside them, are in earlier commits | Rewrite history (destructive, force-push), or accept |

:::warning[Removing a file does not remove it from history]
Deleting a leaked file in a new commit leaves it in every earlier commit and every existing clone. Purging
history (BFG Repo-Cleaner or `git filter-repo`, then a force-push) is a destructive, outward-facing action. If
a **secret** was ever exposed, rotate it first.
:::

## Running it locally

```bash
node scripts/security/scan-private-details.mjs                      # tracked files
node scripts/security/scan-private-details.mjs --tracked --paths --history
node scripts/security/scan-private-details.mjs --dir website/build  # built docs
PRIVATE_TERMS=$'term-one\nterm-two' node scripts/security/scan-private-details.mjs
node --test scripts/security/scan-private-details.test.mjs          # rule tests
```

Exit code `0` means clean, `1` means findings, `2` means a configuration error.

## Limits

- Pattern matching finds **shapes**, not meaning. A private hostname that matches no rule and is not in
  `PRIVATE_TERMS` passes. Keep `PRIVATE_TERMS` current.
- Binary files, files over 2 MB and lines over 4,000 characters are skipped.
- It does not review images or PDFs.
- It cannot judge whether *prose* reveals something sensitive. Review documentation changes by eye as well.
