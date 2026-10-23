# Feature coverage

| Area | Preserved behavior | Verification |
| --- | --- | --- |
| Storefront | Original header/hero, department menus and previews, product rails, dark footer, About/catalog/product layouts and existing URL paths | Desktop/tablet/mobile screenshots and navigation tests |
| Categories | Hierarchy, descendant filters/counts, sample images, ordering, hide/restore, cycle rejection | API tests and migrated relationships |
| Products | Search, facets, sorting, pagination, stock/price filters, features, color variants, gallery/zoom | API/browser checks |
| Product administration | Add/edit/hide/restore, price confirmation, inventory, specifications, images | Editor/API tests |
| Images | Existing processed photos copied; new images resized and watermarked | Every migrated image reference checked; GD upload test |
| Accounts | Shared sign-in, distinct customer/admin password checks, signup, profile, addresses/pins | Role tests and existing-admin login |
| Customer administration | Search, contact editing, map pin and invoice history; no password/role exposure | API/admin screen tests |
| Cart | Guest/account persistence, merge, quantity edits, removal, cross-device customer cart | API and full browser checkout |
| Checkout | Direct order or proforma, phone/TIN, RRA code, RWF/USD, delivery/pickup cards, payment choices, original terms checkbox, installation/notes | Full TIN/USD/delivery browser checkout on isolated database |
| Inventory | Atomic reservations, row locks, one-time stock restoration | Workflow and concurrency tests |
| Orders | Private tokens, customer-ID ownership, immutable prices and delivery snapshots | Source-field verification and workflow tests |
| Proforma | PDF/download/print/share, company/client data, quantities, currency, stamp/signature, multiple pages | PDF text checks and visual review |
| Order administration | Status/payment/date/currency/document/service filters and valid transitions | API/admin tests |
| Manual payments | MTN/Airtel/card/bank instructions, preferences, provider reference and staff verification | API/browser tests |
| MTN MoMo | Durable UUID, authenticated status lookup, verified callback, sandbox separation, retries and safe error messages | State-machine tests; live credentials absent |
| Website chat | Saved history, product links, identification, staff inbox/search, unread/typing/replies | API/browser tests; polling replaces Socket.io |
| Staff notifications | Background order and unread-message updates | Implemented with HTTP polling |
| WhatsApp | Signed webhook, deduplication, inbound media, receipts, search/paging, reply window, durable send IDs, uploads/downloads | Webhook/idempotency tests; live credentials absent |
| Quotes | Requests, product/quantity, admin search/filter/status | API/admin tests |
| Email | PDF proformas, delivery notifications, durable retry queue | Renderer/queue implemented; SMTP absent |
| Content | Existing content fields, navigation groups, branding and business/payment details | Source JSON verified; content editor tested |
| Languages/theme | Original UK/French/Rwandan flag buttons, English/French/Kinyarwanda dictionaries, light/dark/system icon buttons, old preference keys | Each flag/theme, persisted selection, keyboard-accessible names and responsive checks |
| Security | PDO parameters, role enforcement, bcrypt compatibility, output encoding, limits, upload validation, private web root | API/security tests and Apache access checks |
| Background jobs | Windows scheduled worker, CLI for hosted cron | Task registered; disabled services do not send |
| Migration | Consistent snapshot, checksum, unmapped-field rejection, empty-target guard, full comparison, sequence preservation | 316 records / 3,368 fields verified on XAMPP |

Live provider authorization, SMTP delivery, public HTTPS callbacks and public deployment cannot be certified without the missing credentials and hosting configuration. The local application and migrated records are available independently of those services. The rebuilt UI/PDF and polling timing are documented differences, not a claim of pixel-identical conversion.
