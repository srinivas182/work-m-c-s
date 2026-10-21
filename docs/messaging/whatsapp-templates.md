# WhatsApp templates to submit to Meta

Business-initiated WhatsApp messages must use templates approved by Meta. Submit these from **WhatsApp Manager → Message templates** once WURK's Meta Business verification is complete. Approval usually takes minutes to a day; authentication templates are the fastest.

After approval, set the template name in **Admin → Providers → WhatsApp** ("Sign-in code template name").

| Name | Category | Language | Used for | Sprint |
|---|---|---|---|---|
| `wurk_sign_in_code` | Authentication | English (`en`) | Sign-in and password-reset codes | 2 (now) |
| `wurk_booking_confirmed` | Utility | English | Booking confirmation | 7 |
| `wurk_booking_reminder` | Utility | English | Booking reminder | 7 |
| `wurk_payment_received` | Utility | English | Payment receipt | 3 |

## `wurk_sign_in_code` (submit now)

Authentication templates use Meta's fixed wording; choose:

- **Code delivery:** Copy code
- **Security recommendation:** on ("For your security, do not share this code.")
- **Expiry warning:** 5 minutes ("This code expires in 5 minutes.")

WURK sends the code as the single body parameter and again on the copy-code button, which is what Meta expects for authentication templates.

## Utility templates (submit with their sprints)

Wording is drafted with each feature and kept short, factual and free of promotional content so Meta classifies them as Utility (cheaper than Marketing). Each business's name is passed as a parameter, so one approved template serves every business.

## Delivery receipts

Point the WhatsApp webhook in the Meta app at `https://app.wurk.co.za/webhooks/messaging/meta`, with the verify token set in Admin → Providers → WhatsApp, and subscribe to **messages**. Receipts are accepted only with a valid `X-Hub-Signature-256` from the app secret.
