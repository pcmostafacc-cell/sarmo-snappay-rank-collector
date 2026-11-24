# Changelog

## 0.6.0 — 2026-11-24

- Gate OTP login behind one-time owner authorization from Bale, with a server-side claim lock and cooldown.
- Require matching WordPress collector API contract before reading or writing observations.
- Separate overall grid rank, ad rank among sponsored cards, and absolute ad slot.
- Preserve stable product/seller IDs and URLs from cards; support explicitly approved IDs via Actions Variables.
- Make invalid source, incomplete scans, and explicit empty results distinct; invalid observations never count as rank loss.
- Add separate source-health alerts, 24-hour rank coverage summaries, campaign/competitor reports, and a 10-keyword ceiling.
- Add a limited WordPress collector role and PHP lint to the GitHub Actions workflow.
- Search Ads campaign metrics are still not connected; no bid or budget changes are performed.
