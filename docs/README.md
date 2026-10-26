# Documentation

Deep-dive documents for `unraid-plugin-docker-model`.

These documents are written in Chinese. Code, scripts and the top-level README
are in English.

| Document | Contents |
|---|---|
| [01-design-decisions.md](01-design-decisions.md) | Why the apt artifact is used instead of a source build: measured comparison, weighted decision matrix, residual risks and mitigations |
| [02-technical-design.md](02-technical-design.md) | Unraid plugin mechanics, why the CLI plugin and the runner container are separate, install/uninstall flow, ar parsing rationale, registry proxy prefix handling, licensing, version pinning |
| [03-verification-log.md](03-verification-log.md) | Measured evidence: environment baseline, upstream release audit across Unraid 7.2.0 → 7.4.0, binary provenance checks, source-build comparison, end-to-end run on a real Unraid host, pitfalls encountered |
| [04-roadmap.md](04-roadmap.md) | Milestone status, release steps, on-host install verification, Community Applications listing, optional WebGUI, open questions, maintenance cadence |

## Reading order

If you are evaluating whether to trust this plugin:

1. `01-design-decisions.md` — where the binary comes from and why
2. `03-verification-log.md` — the measurements behind those claims

If you are modifying the packaging:

1. `02-technical-design.md` — how the `.plg` and scripts fit together
2. `04-roadmap.md` — what is done and what is still open

## Related top-level documents

- [`../README.md`](../README.md) — project overview (English)
- [`../README.zh-CN.md`](../README.zh-CN.md) — project overview (Chinese)
- [`../CONTRIBUTING.md`](../CONTRIBUTING.md) — development setup and PR checklist
- [`../SECURITY.md`](../SECURITY.md) — reporting vulnerabilities
- [`../CHANGELOG.md`](../CHANGELOG.md) — release history
