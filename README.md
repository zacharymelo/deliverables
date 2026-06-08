# Serial Tracker (prototype)

A **UX prototype** for tracking the lifecycle of each serial (`product_lot`) on a
project, as a list of mini step-indicators on the project card:

> **Manufactured → Shipped → Under Warranty → Support Ended**

It installs **alongside** Lead Tracker (numero `500122` vs `500121`, distinct
name and hook target) so staff can compare the serial-lifecycle UX against the
sales funnel on the same project card.

## Status

- **Display + UX: working.** Renders one mini-stepper per serial on the project card.
- **Live data: NOT wired yet.** With `SERIALTRACKER_SAMPLE = 1` (default) it shows
  **mock serials**, clearly tagged "Sample data", so the look and placement can be
  evaluated immediately.
- **Next step:** verify the real project→serial→(MO/shipment/warranty) linkage via
  `ajax/debug.php?project_id=N`, then wire `SerialLifecycleResolver` to live
  evidence and flip `SERIALTRACKER_SAMPLE` to `0`.

## Why a separate module (not a branch)

A branch is the *same* module — installing it overwrites Lead Tracker, so you'd
only ever see one widget at a time. A separate module with its own numero runs
concurrently, which is what a side-by-side staff comparison needs.

## Known design constraints (from the live setup)

- A project can hold **multiple serials** → rendered as a list, one row per serial.
- Serials are native Dolibarr **`product_lot`**.
- **MO is recorded independently of orders**; **warranty links to the third party
  and the shipment** — so lifecycle evidence resolves off the serial/shipment, not
  the project directly. This is what `ajax/debug.php` exists to map.

## Install

Build a zip and install via Home → Setup → Modules → Deplo/install:

```
python3 bin/build.py 0.1.0
```
