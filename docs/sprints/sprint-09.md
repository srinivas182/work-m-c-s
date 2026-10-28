# Sprint 9 — Online consultations & patient documents: detailed specification

| | |
|---|---|
| **Sprint** | 9 (sprint plan revision 3) |
| **Status** | ✅ Complete — released in v0.10.0, v0.11.0 (9.1b) and v0.12.0 (9.4, 9.5) |
| **Depends on** | v0.9.0 (appointments, deposits, messages, manage links) |
| **Legal review** | Completed (WURK, Oct 2026). **Its specific requirements are needed before 9.4 and 9.5**, which are scheduled last |
| **Delivery** | 5 sub-sprints, one PR each into `develop`; each passes the full CI run (every job checked) before merging |

---

## 1. Objective

**Any business can see clients online as easily as in person** — a tutor, coach, consultant, trainer or practitioner — with a private video link per appointment, paid before it starts. **Health practices can share documents with patients safely**, as the legal review requires.

## 2. The key decision — whose video account hosts the meeting

| Option | Verdict |
|---|---|
| **The business connects its own Google or Zoom account (recommended)** | Meetings belong to the business: its name as host, its own recordings and plan limits, and **WURK never holds the conversation**. Connected once, with Google's or Zoom's own sign-in (OAuth) |
| WURK creates every meeting from one WURK account | Simple for owners, but WURK would host every client's private consultation — a privacy and liability burden, and one account's limits shared by all |
| The business pastes one fixed meeting link | Works with any tool, but **the same link for every client** — anyone given it once can join later. Offered only as a fallback |

## 3. Sub-sprints

### 9.1 Online appointments with a link per appointment

| Item | Details |
|---|---|
| Per service | In person, online, or the client chooses |
| Connections | Google (Meet, via Google Calendar) and Zoom, each connected by the business in Settings; disconnect any time |
| A unique link per appointment | Created when the booking is confirmed; moved or cancelled with the booking |
| Fallback | A fixed meeting link the business enters, clearly marked as shared |
| Join window | The link appears on the client's booking page from 15 minutes before; in messages as below |

### 9.2 Pay before the slot

| Item | Details |
|---|---|
| Online appointments | The business can require **the full price, or a deposit, before the link is issued** — reusing Sprint 8's payments (straight to the business, verified, refunds by the cancellation policy) |
| Unpaid | The time is held for 15 minutes, as for deposits; no link until paid |

### 9.3 Online group classes

| Item | Details |
|---|---|
| Classes | A class at a time, with a capacity (like table covers) and one shared link for that session |
| Booking | Clients book a place; the class timetable section links to booking |
| Messages | Confirmation and reminder with the joining details, as for appointments |

### 9.4 Health consultations online *(after the legal review's requirements)*

| Item | Details |
|---|---|
| Switched on for health practices | Only once the conditions from the review are in place (e.g. consent before an online consultation, the practitioner's registration details shown) |
| Wording | Fixed wording for consent and notices, as the review specifies |

### 9.5 Patient documents *(after the legal review's requirements)*

Health records are **special personal information under POPIA**. As a baseline, to be confirmed against the review:

| Item | Details |
|---|---|
| Sharing | The practice uploads a document for a patient; the patient opens it from a private link **and a one-time code** sent to them |
| Protection | Stored encrypted, separately from website media; never public; every view and download logged |
| Access | Only staff the practice allows; the patient only their own documents |
| Retention and deletion | As the review specifies |

## 4. Acceptance criteria

| Sub-sprint | Acceptance |
|---|---|
| 9.1 | A booked online appointment gets its own Meet or Zoom link (tested against the providers' APIs, simulated in CI); moving or cancelling updates it |
| 9.2 | No link until paid; refunds follow the policy (tested) |
| 9.3 | Classes never exceed capacity (database-enforced, as for tables) |
| 9.4 | Online health consultations unavailable until every review condition is met (tested) |
| 9.5 | A patient sees only their own documents, after a one-time code; every access logged (tested); retention as the review requires |

## 5. Decisions to confirm

1. **Businesses connect their own Google or Zoom accounts** (recommended), with a fixed link as fallback.
2. **Messages**: WhatsApp's fixed templates can't carry a new link type without Meta approval. Online appointments get **their joining link in email and on the booking page**; SMS and WhatsApp keep the existing templates (their manage link leads to the booking page, which shows the joining link). New WhatsApp templates can be added later.
3. **9.4 and 9.5 follow the legal review's specific requirements** — scheduled last.

## 6. Needed from WURK

| Item | Needed by |
|---|---|
| **The legal review's requirements** (consent wording, retention, access, telehealth conditions) | 9.4 and 9.5 |
| **A Google Cloud OAuth app** (Calendar API) and **a Zoom app**, for businesses to connect their accounts | 9.1 (test credentials are enough to build) |

---

## 7. Progress log

| Sub-sprint | PR | Notes |
|---|---|---|
| 9.1 Online appointments | #70 | 782 backend tests on SQLite and MySQL 8; 371 frontend; all browser tests incl. a customer booking online on a published site. Found: an app-domain route segment (`oauth`) not reserved as a handle (architecture test) |
| 9.2 Pay before the slot | #71 | 783 backend tests on SQLite and MySQL 8; 371 frontend; all browser tests |
| 9.3 Group classes | #72 | 786 backend tests on SQLite and MySQL 8; 371 frontend; all browser tests incl. a guest booking a class on the published gym site. Classes paid at the class; online payment for classes proposed as a follow-up. Found: public rate limits shared across all businesses per IP (fixed: per business and visitor); a button's spoken name didn't contain its visible words; an in-place-editable label on a hidden button |
| 9.3b Online payment for classes (agreed follow-up) | #73 | 791 backend tests on SQLite and MySQL 8; 371 frontend; booking browser tests together. Found: a status column too short for `pending_payment` on MySQL |
| 9.1b Meeting links per team member (agreed change) | #77 | 842 backend tests on SQLite and MySQL 8; booking browser tests |
| 9.4 Health consultations online | #83 | 913 backend tests on SQLite; Bookings on MySQL 8; 378 frontend; browser test of the Health practice card. Consent wording is a working default for the lawyers to confirm |
| 9.5 Patient documents | #85 | 977 backend tests on SQLite; Records and Payments on MySQL 8; all browser tests incl. the full patient journey (desktop and phone). Found: rate limits shared per IP across businesses (also in Payments — fixed); MySQL TIMESTAMP's 2038 limit (Records uses DATETIME) |

## 8. Changes agreed with WURK (29 Oct 2026)

1. **Meeting links, not account connections**: businesses paste a Google Meet or Zoom link — **one per team member**, with a business default — instead of connecting Google or Zoom accounts (too hard for most owners). The account-connection code stays, hidden unless WURK enables it. Owners are advised to use the waiting room / lobby.
2. **Legal review done**; 9.4–9.5 proceed with configurable defaults: consent before an online consultation, practitioner registration number shown, access only for staff the practice allows.
3. **Patient records can never be deleted** — append-only: corrections add a new version (the original kept), every view logged. *For the lawyers:* keeping records indefinitely goes beyond the HPCSA minimum (6 years; until 21 for minors) and POPIA's "no longer than necessary" — a defined period can be set later without a rebuild.

## 9. Decisions (30 Oct 2026)

1. **Consent wording confirmed by WURK's lawyers** (version v1, as built in 9.4).
2. **Patient records are kept for 6 years** (replacing "never deleted"): nobody can delete a record during the 6 years, counted from the patient's most recent document; corrections add a new version. After 6 years records are flagged for the practice, and the owner can remove them securely (logged). Nothing is deleted automatically. The period is a setting.
