# Contributing to particle-academy/fancy-mlm (PHP)

Notes for anyone, human or agent, working ON this package's code. How to USE the
package is in [AGENTS.md](./AGENTS.md); what it is, is in [README.md](./README.md).
`CLAUDE.md` is a symlink to this file, so Claude Code reads these notes when it
works in this repo.

## Testing

```bash
composer install
composer test        # pest
```

- Tests live in `tests/` (Pest; shared vectors in `tests/fixtures/`).
- **The core requires zero framework.** `require` is PHP only; Laravel code
  (`FancyMlm\Laravel\`) is an optional bridge, with `illuminate/support` only
  suggested.
- **Parity with Node.** The same `CompensationPlan` JSON must give the same
  rewards as [`@particle-academy/fancy-mlm`](https://github.com/Particle-Academy/fancy-mlm-js);
  the sponsor-correction fixture is byte-identical to the Node package's copy.
- SQLite is what the suite runs. The MySQL/MariaDB sponsor triggers are not run
  by it.

## Changelog

Update `CHANGELOG.md` (`## [Unreleased]`) in the same commit as the change.
