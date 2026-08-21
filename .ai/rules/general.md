---
paths:
  - vite.config.js
---

# General

## Use the secured Herd hostname for Vite
Serve development assets and HMR from koskalk.test, not localhost. Herd's TLS certificate is valid for the .test hostname; localhost causes browsers to reject CSS with ERR_CERT_COMMON_NAME_INVALID.
