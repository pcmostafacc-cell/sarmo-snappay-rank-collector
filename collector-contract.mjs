export function assertCollectorContract(status) {
  if (status?.api_version !== '0.7.0' || status?.otp_mode !== 'owner-armed-rate-limit-aware-one-time') {
    throw new Error('WordPress collector API mismatch; install the matching Sarmo SnappPay Monitor plugin before collecting');
  }
}
