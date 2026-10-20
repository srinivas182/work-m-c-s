# Sprint plan to closed beta

**Assumptions:** 2-week sprints; team of ~10 — 1 architect/tech lead, 4 Laravel developers, 3 React developers, 2 designers (senior visual + UI/UX), 1 DevOps, 2 QA, plus a project manager. Designers work one sprint ahead.

| Sprint | Focus | Deliverables |
|---|---|---|
| 0 | Foundations | PRD & architecture sign-off; design system; high-fidelity key screens; AWS Cape Town environments; Terraform; CI/CD; start DebiCheck and WhatsApp provider applications |
| 1 | Platform core | Tenant router & central DB; WURK ID (OTP + password); roles & permissions; audit logging; isolation test harness |
| 2 | Super admin | Packages & entitlements; provider settings (payments, SMS, WhatsApp, email); WURK Credits ledger & rate card; security settings; submit default WhatsApp templates |
| 3 | Signup & billing | wurk.co.za marketing site; onboarding wizard; subdomain checker; card, DebiCheck, cash (OTP) payments; VAT invoices; dunning & suspension; proration |
| 4 | Website builder | Premium block library; Puck visual editor; brand colour engine; pages, menus, header/footer; version history |
| 5 | Website engine | Node render service; Cloudflare for SaaS domains & SSL; caching & purge; automatic SEO & AEO |
| 6 | Templates & AI onboarding | First category templates; AI site generation; go-live checklist; media library with quotas |
| 7 | Appointments | Staff/branch availability; booking widget; deposits; cancellation/no-show rules; reminders |
| 8 | Online shop | Catalogue, variants, stock, cart, checkout; PayFast, Yoco, Ozow, Paystack, Peach; orders & refunds |
| 9 | Multi-branch & shipping | Branch managers; per-branch merchant accounts, prices, stock; inter-branch settlement; courier integrations |
| 10 | CRM & messaging | Leads & pipelines; forms & custom fields; rule-based chatbot; event template engine (email/SMS/WhatsApp) |
| 11 | Referral engine | Self + 7 levels; pending/available; wallet rules; attribution; Record sale; customer payouts; customer portal; My WURK |
| 12 | Agents | Sponsor hierarchy; package commissions & clawback; EFT batch payouts; agent dashboard; demo sites with auto-delete |
| 13 | Reports & campaigns | Tenant reports; SaaS metrics; ClickHouse analytics; campaigns & offers; staff management; weekly WhatsApp summary |
| 14 | Self-service & support | AI assistant; help centre & tooltips; consent-based impersonation; tickets; tenant health; backup/restore & export; tenant move tool |
| 15 | Hardening | Penetration test; load testing at scale; shard rollout rehearsal; DR drill; accessibility; design polish pass; UAT |

**Closed beta** after Sprint 15 (~8 months) with 20–50 pilot businesses. 2–3 sprints of beta feedback before public launch (~9–10 months).

## Team size sensitivity

| Team | Sprints to beta | Approx. time |
|---|---|---|
| ~6–7 | 22–24 | 11–12 months |
| ~10 | 16 | 8 months |
| ~14 (two squads) | 12–13 | 6 months |
