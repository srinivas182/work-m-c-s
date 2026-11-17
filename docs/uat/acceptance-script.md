# Beta acceptance script (PRD §18, criteria 1–12)

**Sprint 18.6b.** Run with WURK before go-live. **Who runs it:** the WURK team, plus 2–3 friendly real businesses (a salon, a shop, a practice) for criteria 1, 4 and 5 (decision 18-D).

**How to record:** write each step's result in the **Result** column as **Pass** or **Fail**, with the date and the person's initials. Log each failure as a bug (see "Bug bash" below).

Criteria that need the cloud are run on **staging in Sprint 17**:
- **2**: a custom domain with automatic SSL;
- **12**: full-scale load, the timed restore drill, and the external penetration test.

Automated tests already cover each criterion; these are noted under each one. The script checks the same things by hand, with real people and real accounts.

## 1. Sign up, AI site, pay, publish in under 30 minutes, without help
**Run by:** a friendly business owner who hasn't seen WURK. Time it; WURK only watches.

| # | Step | Expected | Result |
|---|---|---|---|
| 1.1 | From wurk.co.za, choose a package and sign up with a mobile number | Code by SMS; account created | |
| 1.2 | Choose the business's category and a design | Site built with pages, menus and sample content | |
| 1.3 | Use "Write my website with AI" with a few sentences about the business | Pages rewritten in the business's words | |
| 1.4 | Replace the stand-in photos with their own; set logo and colours | Launch checklist shows photos done | |
| 1.5 | Pay for the package with a card (Paystack test card on staging) | Subscription active; invoice emailed | |
| 1.6 | Publish | Site live at `handle.wurk.co.za` | |
| 1.7 | Stop the clock | **Under 30 minutes**, no help asked for | |

*Automated:* signup.spec, ai.spec, site-setup.spec, launch-checklist.spec.

## 2. Custom domain with automatic SSL (Sprint 17, staging)
| # | Step | Expected | Result |
|---|---|---|---|
| 2.1 | Settings → Domain: enter a domain WURK owns for testing | DNS instructions shown in plain words | |
| 2.2 | Add the DNS records at the registrar, following only the screen | WURK detects them; certificate issued automatically | |
| 2.3 | Open the domain in a browser | Site loads over HTTPS; the subdomain redirects to it | |

## 3. Every template ≥ 90 Lighthouse mobile, and passes design review
| # | Step | Expected | Result |
|---|---|---|---|
| 3.1 | CI "performance gate" on the release commit | All 24 ≥ 90, layout shift < 0.1 | |
| 3.2 | WURK design review of each template, on a phone and a computer | Signed off, or changes logged | |

*Automated:* the performance gate; template-contrast.spec (WCAG 2.2 AA in many colours).

## 4. Book, pay a deposit, WhatsApp confirmation and reminder, appears in the CRM
**Run by:** a friendly salon or practice with WhatsApp connected on staging.

| # | Step | Expected | Result |
|---|---|---|---|
| 4.1 | As a customer, book a service with a deposit on the business's site | Deposit paid through the business's gateway | |
| 4.2 | Check the customer's WhatsApp | Confirmation arrives within a minute | |
| 4.3 | Wait for (or bring forward) the reminder time | Reminder arrives on WhatsApp | |
| 4.4 | As the owner, open Customers | The customer is there, with the booking and payment | |

*Automated:* public-booking.spec, booking message and deposit tests.

## 5. Shop purchase through the business's own gateway; money settles to the business
| # | Step | Expected | Result |
|---|---|---|---|
| 5.1 | Buy a product on the business's shop with a card | Order placed; confirmation sent | |
| 5.2 | Check the business's own gateway dashboard | The payment is in **their** account, not WURK's | |

*Automated:* checkout.spec, shop payment tests.

## 6. Referral commissions: self + 7 levels; refunds reverse them
| # | Step | Expected | Result |
|---|---|---|---|
| 6.1 | Set up an 8-person referral chain; make an online order, a paid booking and a Record sale | Commissions at each level match the package's rates | |
| 6.2 | Refund one of them | Its commissions are reversed at every level | |

*Automated:* Referrals feature tests (levels, refunds, caps).

## 7. My WURK across businesses; no data leaks
| # | Step | Expected | Result |
|---|---|---|---|
| 7.1 | A customer of two businesses signs in to My WURK | Both businesses listed; each opens with its own data | |
| 7.2 | — | CI isolation sweep green on the release commit (every table, every screen) | |

*Automated:* IsolationSweepTest, portal.spec.

## 8. Branch managers see only their branch; per-branch merchant accounts
| # | Step | Expected | Result |
|---|---|---|---|
| 8.1 | Sign in as a branch manager | Only their branch's bookings, sales and staff | |
| 8.2 | Pay at each branch | Each payment goes to that branch's merchant account | |

*Automated:* team-branches.spec, branch payment tests.

## 9. Agent commissions, clawbacks, EFT batch payouts
| # | Step | Expected | Result |
|---|---|---|---|
| 9.1 | Agents in a sponsor line sign up businesses that pay | Commissions match the agent plan | |
| 9.2 | A business is refunded or cancels in the clawback window | Clawback applied | |
| 9.3 | HQ runs the EFT payout batch and marks it paid | Wallets updated; agents notified | |

*Automated:* Agents feature tests, agent.spec.

## 10. Dunning, suspension, reactivation
| # | Step | Expected | Result |
|---|---|---|---|
| 10.1 | Make a renewal fail (staging test card that declines) | Reminders on the schedule; the owner sees a clear banner | |
| 10.2 | Let the schedule run out | Business suspended (site shows a neutral notice; data kept) | |
| 10.3 | Pay | Reactivated straight away | |

*Automated:* Billing dunning tests.

## 11. Cash payments with OTP, receipts, audit entries
| # | Step | Expected | Result |
|---|---|---|---|
| 11.1 | Record a cash payment at HQ | The owner's OTP is required | |
| 11.2 | — | Receipt emailed; audit entry in Admin → Audit | |

*Automated:* cash payment tests, audit.spec.

## 12. Scale, disaster recovery, penetration test (Sprint 17, staging)
| # | Step | Expected | Result |
|---|---|---|---|
| 12.1 | k6 target profile (docs/performance/load-testing.md) | p95 < 300 ms, errors < 0.1% at 1,000 requests a second | |
| 12.2 | Timed restore drill on the managed database (docs/runbooks/restore.md) | Data lost ≤ 15 min; back within 4 h; recorded in Admin → System | |
| 12.3 | CREST-accredited external penetration test | No open critical or high findings | |

## Bug bash

**When:** one session of about 2 hours, with the WURK team. Before it starts, the team agrees the business types each person plays.

**Log each finding** with:
- the screen and the steps to repeat it;
- what was expected and what happened;
- a screenshot;
- severity: **blocker**, **major** or **minor**.

**What happens to findings:**
- Every **blocker** and **major** is fixed before go-live.
- Each **minor** is either fixed or scheduled into a named sprint.
- Watchtower records any crash automatically. Its fix request can be pasted to engineering as it is.
