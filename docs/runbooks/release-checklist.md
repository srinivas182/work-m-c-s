# Release checklist

Run for every release, `develop` → `main`.

1. **CI green** on the release PR: every job, including the accessibility crawl, the performance gate, the security audits and the MySQL parts.
2. **Version.** Set `VERSION` to the release number (for example `0.19.0`). Move CHANGELOG's "Unreleased" items under the new version and date.
3. **Screen-reader check** (as the accessibility statement promises). Go through sign-in, booking, checkout and the page editor's "Add section":
   - with **NVDA + Firefox** or **VoiceOver + Safari** on a computer;
   - with **TalkBack** or **VoiceOver** on a phone.

   Note anything confusing in the release PR.
4. **Deployment notes.** Read the release's rows in docs/deployment/docker.md: new settings, scheduled jobs, one-off steps.
5. **Backup first.** Take a database snapshot before the release step, especially when a release rewrites tables.
6. **Release.**
   - Merge to `main`, then **create the tag** (`vX.Y.Z`) on GitHub.
   - Build and deploy.
   - Run the release step (`migrate`). It migrates the central database, then every shard, canary first.
7. **After deploy.**
   - Watchtower shows no new problems for 30 minutes.
   - Admin → System shows every shard up to date.
   - The k6 smoke profile against production's public pages passes.
