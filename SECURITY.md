# Security and secret handling

- Never commit Bale tokens, WordPress Application Passwords, OTP codes, phone numbers, storageState, cookies, or full session logs.
- Store WordPress credentials only as GitHub Actions Secrets. Use a dedicated `Sarmo Rank Collector` WordPress account and a revocable Application Password; do not use the administrator password.
- Store approved merchant/product IDs only as non-secret GitHub Actions Variables. Do not add competitor products to the ownership allowlist.
- The collector requests an OTP only after the owner explicitly arms one attempt with `/login` in the authorized Bale chat. It consumes the authorization before requesting an SMS and has a cooldown.
- Report suspected credentials in the repository immediately, revoke them, and rotate affected access.
