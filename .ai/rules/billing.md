---
paths:
  - 'app/Services/Billing/**'
---

# Billing

## Separate billing availability from provider credentials
BILLING_AVAILABLE defaults off and gates user-initiated checkout and payment-management routes, including direct checkout creation. Configured provider credentials alone must not open commerce. Keep signed webhook reconciliation active while checkout is unavailable.
