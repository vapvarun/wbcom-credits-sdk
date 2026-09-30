# REST and webhook error codes

Every error the SDK's routes return is a `WP_Error`, sent as
`{ "code": "...", "message": "...", "data": { "status": 000 } }`.

**Consumers match on `code` and show their own text, in their own text
domain.** The `message` is for logs and developers. It is translated until
2.0.0 and plain English after that, and it may change without notice. A code
never changes meaning. A new code is added to this table in the release that
introduces it. See [HEADLESS-PLAN.md](HEADLESS-PLAN.md).

Always keep a generic fallback message for a code you do not recognise.

## Checkout: `POST wbcom-credits/v1/{slug}/checkout/{gateway}`

| Code | Status | Means | Suggested wording |
|---|---|---|---|
| `checkout_disabled` | 403 | The slug's credit sales are switched off. | Credit purchases are not available right now. |
| `unknown_gateway` | 404 | No gateway with that id is registered for the slug. | This payment method is not available. |
| `gateway_unavailable` | 409 | The gateway is registered but not configured or not enabled. | This payment method is not available. |
| `billing_incomplete` | 400 | Required billing fields are missing. `data.fields` lists their keys. | Please complete your billing details. |
| `coupon_invalid` | 400 | The coupon code does not exist for the slug. | That coupon code is not valid. |
| `coupon_expired` | 400 | The coupon is past its expiry date. | That coupon has expired. |
| `coupon_used_up` | 400 | The coupon has no redemptions left. | That coupon has been used up. |
| `coupon_busy` | 409 | Another checkout holds the coupon's last redemption. Retry. | Someone else is using this coupon. Please try again. |
| `topup_failed` | 500 | A free (100%-off) checkout could not credit the balance. | Could not add the credits. Please try again. |
| `gateway_error` | 502 | The provider refused to open a checkout session. | The payment could not be started. Please try again. |

## Claim after return: `POST wbcom-credits/v1/{slug}/claim/{gateway}`

| Code | Status | Means | Suggested wording |
|---|---|---|---|
| `unknown_gateway` | 404 | As above. | This payment method is not available. |
| `gateway_unavailable` | 409 | As above. | This payment method is not available. |
| `not_your_session` | 403 | The checkout belongs to another user. | This payment belongs to a different account. |
| `unknown_session` | 404 | No checkout is recorded for this session id. | We could not find that payment. |

## Refund (admin): `POST wbcom-credits/v1/{slug}/refund/{gateway}`

| Code | Status | Means |
|---|---|---|
| `unknown_gateway` | 404 | As above. |
| `refund_failed` | 502 | The provider did not accept the refund request. |

## Admin top-up or adjustment: `POST wbcom-credits/v1/{slug}/topup`

| Code | Status | Means |
|---|---|---|
| `wbcom_credits_invalid_user` | 400 | The user id does not exist. |
| `wbcom_credits_zero_amount` | 400 | The adjustment amount is zero. |
| `wbcom_credits_not_money` | 400 | `amount_money` was sent to a credits-mode (not money-mode) slug. |
| `wbcom_credits_db_error` | 500 | The ledger row could not be written. |

WordPress core may also return its own codes (`rest_forbidden`,
`rest_invalid_param`, `rest_cookie_invalid_nonce`, ...) before a request
reaches the SDK. Treat those with the same generic fallback.
