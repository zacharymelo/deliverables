# Serial Tracker (prototype)

A **UX prototype** that tracks a *thing's journey*, anchored on the **order line**
rather than the serial (which starts the story too late). Installs **alongside**
Lead Tracker (numero `500122`).

## Model — two phases, joined at "Shipped"

**Front half — per order line (counts, no per-unit identity yet):**

> **Ordered (N) → Picking (x) → Shipped (y)**

**Back half — per serial (once a lot is attached at shipment):**

> **Manufactured (MO, traced by lot) → Shipped → Under Warranty → Support Ended**

The only tie between an order and its physical goods is the serial/lot attached
at shipment, so **MO is not a forward stage on the order** — it is resolved
per-serial (by lot) on the serial detail page.

## Surfaces

- **Project → Fulfillment tab** — order lines with their front-half journey + serial chips.
- **Customer → Fulfillment tab** — every order line for a company.
- **Serial detail page** (`serial_card.php`) — one serial's back-half journey + MO/production trace.
- **Slim summary** on the project main card — e.g. "5 units across 3 lines · 3 shipped · 1 in warranty" → links to the tab.
- **Subtitles + tooltips** on every step (ref / status / date + a "what's still needed" hint on open steps), mirroring the Order Progress tracker.

## Status

- **Display + UX: working** with `SERIALTRACKER_SAMPLE = 1` (default) — one coherent mock
  dataset backs every surface, tagged "Sample data".
- **Live data: NOT wired.** Verify the order→shipment→lot linkage via
  `ajax/debug.php?project_id=N` (enable `SERIALTRACKER_DEBUG`), then wire the
  `liveLines()`/`liveSerial()` stubs and flip `SERIALTRACKER_SAMPLE` to `0`.

## Install

```
python3 bin/build.py 0.2.0
```
Then Home → Setup → Modules → Deploy/install external module.
