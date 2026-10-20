# Changelog

All notable changes to WURK are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org/).

## [Unreleased]

### Added — Sprint 0.1 (skeleton)
- Laravel 13 application with React 19, TypeScript 6 (strict), Inertia v3, Tailwind CSS v4, Vite 8, pnpm.
- Module system: `modules/*/module.json` manifests, `ModuleRegistry` (discovery, validation, dependency ordering, cycle detection), `ModuleServiceProvider` base class (migrations, web routes, versioned `/api/v1` routes, contribution declarations).
- `php artisan wurk:modules` and `php artisan wurk:make-module`.
- Platform module (kernel, temporary welcome page) and Health module (`/health/live`, `/health/ready`, pluggable checks via `HealthCheck` contract).
- Module-namespaced Inertia pages (`Module::Page`).
- Architecture tests enforcing module boundaries, strict types, no debug helpers, `env()` only in config.
- Pest, Larastan (level 8), Pint, ESLint, Prettier, Vitest.
- Docker Compose dev stack (FrankenPHP + Octane, Vite, MySQL 8.4, Redis, Meilisearch, Mailpit); `scripts/dev` and `scripts/check`.

### Added — Sprint 0.2 (CI)
- GitHub Actions CI: change detection, quick checks on draft PRs, full tests and build when ready for review, Docker dev-image build when Docker files change, a single always-running `CI` summary job used as the required status check.
- Concurrency cancellation of superseded runs; Composer, pnpm and PHPStan caches.
- Branch protection on `develop` and `main` (PR required, `CI` must pass).
- Feature tests no longer depend on a compiled Vite build.

### Added — Sprint 0.3 (design system)
- Brand palette engine (OKLCH): one brand colour → 12-step light and dark scales plus brand-tinted neutrals, with a WCAG 2.2 contrast guard (muted text ≥ 4.5:1, body text ≥ 7:1, focus ring ≥ 3:1, text on solid fills ≥ 4.5:1). Tested on 16 edge-case colours and 300 random colours.
- Generated design tokens (`resources/css/tokens.css`, `pnpm tokens`), semantic colour roles, type scale (Inter + Plus Jakarta Sans, self-hosted), radius, elevation, motion; light, dark and system themes without a flash on load; reduced-motion support.
- Components: Button, Input, Textarea, Field (label/hint/error wiring), Select, Checkbox, Switch, Radio group, Card, Badge, Avatar, Tooltip, Dropdown menu, Dialog (bottom sheet on mobile), Sheet, Tabs, Toast, Table, Skeleton, EmptyState, StatCard, PageHeader.
- Back-office AppShell: collapsible sidebar, top bar (search ⌘K, notifications, theme, account), mobile bottom navigation and menu sheet, skip link; navigation built from module `menu()` declarations.
- `/_design` showcase (not in production) with live brand colour lab and contrast report.
- Playwright end-to-end smoke and axe accessibility checks (desktop and mobile, light and dark) in CI, with failure annotations on the PR.
- `Table` requires a `label` and its scroll area is keyboard-focusable; `Field` supports composite controls (e.g. Select) via a render function.

### Fixed
- Branch protection now applies to administrators (an admin merge could bypass the required `CI` check).

### Changed
- Removed the framework default `/up` route in favour of the Health module.
