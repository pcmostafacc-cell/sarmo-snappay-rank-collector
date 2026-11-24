# Changelog

## 0.7.0 — 2026-11-25 (prepared; not deployed live)

- Expand `/competitors <keyword>` into a timed comparison of Sarmo products and competitor cards, including visible placement, ad badge, product price, card score, review count, discount, and availability when present.
- Distinguish the displayed product price from advertising cost; the consumer marketplace page does not disclose the advertiser's click or placement charge.
- Clarify incomplete scans so a missing Sarmo product is not presented as absent from all results.
- Detect HTTP 429 and provider rate-limit messages before asking Bale for an OTP; persist a 12-hour provider lock and reconstruct it from the latest sanitized observation after an update.
- Prevent `/login` and OTP acceptance during the lock, preserve any saved session, and expose the exact Tehran unlock time through `/login_status`.
- Send the specific, deduplicated cause to Bale and keep invalid source results out of rank-loss alerts.
- Add a cheap scheduled preflight: outdated WordPress contracts and active 429 locks skip Chromium installation and browser collection.
- Add PHP acceptance checks for rate-limit protection and the Bale market report, plus Node tests for 429 recognition and the new collector contract.
- This candidate is not installed on live WordPress; the active live version remains 0.6.0 until the next approved update.

## 0.6.0 — 2026-11-24

- Gate OTP login behind one-time owner authorization from Bale, with a server-side claim lock and cooldown.
- Require matching WordPress collector API contract before reading or writing observations.
- Separate overall grid rank, ad rank among sponsored cards, and absolute ad slot.
- Preserve stable product/seller IDs and URLs from cards; support explicitly approved IDs via Actions Variables.
- Make invalid source, incomplete scans, and explicit empty results distinct; invalid observations never count as rank loss.
- Add separate source-health alerts, 24-hour rank coverage summaries, campaign/competitor reports, and a 10-keyword ceiling.
- Add a limited WordPress collector role and PHP lint to the GitHub Actions workflow.
- Search Ads campaign metrics are still not connected; no bid or budget changes are performed.
