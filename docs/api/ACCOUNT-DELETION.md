# Account deletion and ownership transfer

Any user can delete their own account from inside the app (an App Store / Google Play requirement). Deletion is **anonymisation**: the person's
details are removed and the account can never be used again, while the farm records the person helped create stay intact for everyone else.

All endpoints are under `/api/v1`. They work for the web cookie session (send `X-XSRF-TOKEN` and the stateful `Origin`) **and** for the mobile
Bearer token (no cookie, no `Origin`). They need only a signed-in account: no verified email, no onboarding, no farm. An unverified account can delete itself.

| Method & path | Who | Notes |
|---|---|---|
| `GET /account/delete/check` | signed-in user | Is deletion allowed now? Lists blockers. Call it before showing the delete screen. |
| `POST /account/delete/request-code` | signed-in user | Emails a 6-digit code (for Google-only accounts, which have no password). `429` + `Retry-After` during the 60 s cooldown. |
| `POST /account/delete` | signed-in user | Deletes now. Body `{confirm: "DELETE", password}` **or** `{confirm: "DELETE", code}`. |
| `POST /farm/transfer-ownership` `{member_id}` | farm owner (`team.transfer_ownership`) | Hands the farm to another active member; you become a manager. |
| `POST /platform-admin/users/{user}/delete` `{reason}` | platform role `admin` | Same deletion, same blockers, for support requests. |

## Check

```json
GET /account/delete/check  ->  200
{ "data": { "allowed": false, "has_password": true, "confirmation": "DELETE",
    "blockers": [ { "code": "owner_must_transfer",
                    "message": "You own the farm \"Green Acres\" and it has other members. Transfer ownership to another member before deleting your account.",
                    "farm": { "id": "…", "name": "Green Acres" } } ] } }
```

| Blocker `code` | Meaning | How the user resolves it |
|---|---|---|
| `owner_must_transfer` | They own a farm that has other **active** members (one blocker per farm, `farm` names it). | `POST /farm/transfer-ownership`, or remove the other members. |
| `marketplace_deals_open` | They have marketplace deals **in progress** (`count`; `shops[]` when it is on a shop they own). | Complete or cancel the deals. |
| `platform_admin_protected` | The account holds a platform administrator grant. | A platform admin revokes the grant first. |

"In progress" means a marketplace deal in status `accepted` (both sides agreed; neither side has completed or cancelled it), either as the buyer or on a
shop the user owns. There are no payments or orders beyond deals, so nothing else counts. Pending offers and waiting seller confirmations are **not**
blockers; they are withdrawn automatically.

## Delete

```json
POST /account/delete   { "confirm": "DELETE", "password": "…" }      // or { "confirm": "DELETE", "code": "123456" }
200  { "data": null, "meta": {}, "message": "Your account has been deleted." }
409  { "code": "account_deletion_blocked", "details": { "blockers": [ { "code": "…", "message": "…" } ] } }
422  confirm is not exactly DELETE / wrong password / invalid or expired code (errors keyed by field)
429  too many attempts (5 per 10 minutes per user)
```

Either method works for any account (a password account may also choose the emailed code; a Google-only account can only use the code).
Blockers are checked **before** the credential, so a blocked request never spends the emailed code. Everything runs in one database transaction:
on any error nothing changes and the request can be repeated. After success every token and session is already gone, so a repeat of the same
request returns `401` (the account no longer exists). Expect `401` on the next call of the app and send the user to the signed-out screen.

## Ownership transfer

```json
POST /farm/transfer-ownership   { "member_id": "<id from GET /farm/members>" }
200  { "data": { "new_owner": { "role": "owner", … }, "previous_owner": { "role": "manager", … } }, "message": "Ownership transferred." }
```

Owner only. `member_id` is the **membership id** (the `id` of a row in `GET /farm/members`), and the target must be an active member of the same farm
(`404` otherwise, including a member of another farm). `422 cannot_transfer_to_self`. The farm always has exactly one owner afterwards; both changes
are audited as `team.member_role_changed`. Rate limited (5 per 10 minutes).

## For app store review: what is deleted, what is kept, and why

**Where:** inside the app (account settings; the frontend builds the screen on the endpoints above). The same flow works on the web. It needs no support ticket and takes effect immediately.

**Deleted or removed immediately**

- Name, email address, password and the Google sign-in link; the email address is released, so the same address can sign up again as a brand new account.
- Every sign-in: all mobile tokens/devices and all web sessions; any pending verification or reset codes; calendar-subscription links; personal notification inbox and notification preferences.
- Access: every farm membership ends. A farm the person alone belonged to is archived (nobody can open it; its paid plan stops renewing). Invitations they sent are cancelled; invitations addressed to their old email can no longer be accepted.
- Marketplace: their shops are closed and listings withdrawn; the shop's private contact details (phone, WhatsApp, email, address) are erased; pending offers are withdrawn; a phone number they gave for a deal is erased.
- A final "your account was deleted" email is sent to the old address (it holds no personal data and no link).

**Kept, and why**

- Farm records (production, stock movements, health, sales, invoices, finance ledger, tasks) and audit logs. These are the **farm's** business and legal records; other team members and accountants rely on them, and finance records may have to be retained. Each record that showed the person's name now shows **"Deleted user"**.
- Business data about other people on those records (buyer or customer names, supplier contacts) and completed marketplace deal summaries. They belong to the farm or the counterparty, not to the deleted user.
- A row for the user id itself, with no personal data, because those records refer to it. The audit trail of the deletion itself holds no personal data (the actor is the internal user id).

**Not possible while:** they own a farm with other members (transfer ownership first), they have marketplace deals in progress, or they are a platform administrator.

**Billing:** farm plans are billed internally; there is no card on file or recurring mandate at a payment provider to cancel. Deletion schedules the paid
plan to end at the close of its period (`cancel_at_period_end`) for farms that are archived, and marketplace seller plans are prepaid periods that do not
renew. **No refund is issued.**

## Name snapshots (what happens to copies of a person's name)

Almost every screen reads a person's name from the user account at display time, so anonymising the account is enough. The only copies are:

| Where | Field | Decision |
|---|---|---|
| `audit_logs` | `resource_label` on `team.member_removed`, `team.member_role_changed` (the member's name), `team.invitation_*` (the invitee's email) and `platform.user_suspended` (the user's email) | Replaced with `Deleted user`; the entry, actor, time and facts stay. |
| `farm_invitations` | `email` of invitations addressed to the old email | Replaced with a unique placeholder; the invitation is expired. |
| `farm_invitations` | `email` of invitations the user sent to other people | Kept (the invitee's address is not the deleted user's data); the invitation is cancelled. |
| `marketplace_deals` | `buyer_contact_phone` | Erased for the buyer's deals. |
| `marketplace_shops` | `contact_phone`, `contact_whatsapp`, `contact_email`, `address_line` | Erased on the shops that are closed. |
| `invoices.seller_name`, sales/finance `customer_name`, `contacts.name` | the **farm's** or a **customer's** name, not the user's | Left alone: business data. |

## Platform admin

`POST /platform-admin/users/{user}/delete` `{reason}` (role `admin`) runs the same deletion with the same blockers (`409 account_deletion_blocked`,
which includes the admin's own account via `platform_admin_protected`). `reason` is kept in the platform audit trail as `platform.user_deleted` (no
personal data). Repeating it on a deleted user succeeds with `already_deleted: true`.
