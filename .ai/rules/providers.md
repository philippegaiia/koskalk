---
paths:
  - 'app/Providers/*.php'
---

# Providers

## Rate-limit routes inline with throttle:count,min
Apply simple rate limits inline per route or group with ->middleware('throttle:count,min'). Register a named RateLimiter::for(...) in a service provider when a route needs composite or user-aware limits a single inline throttle cannot express (the app does this for beta-invite-accept).
