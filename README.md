# Serial Tracker

An internal **fulfillment + install-base hub** for Dolibarr, anchored on the
**order line**. Installs alongside Lead Tracker (numero `500122`). All data is
**live** — the demo/sample mode has been removed.

## What it does

- **Project & Customer → Fulfillment tab** — a **deliverables table**: every order
  line with Ordered / Shipped / Outstanding, deduped serial chips, and the
  product's company-wide **ATP shortfall**, with a one-click **Create MO**
  (manufactured) or **Reorder** (purchased) action. Reorder surfaces the
  **cheapest** and (if a different vendor) the **fastest** supplier — unit price +
  `delivery_time_days` lead — and pre-selects the chosen vendor in the PO seed.
  Product name → product card (icon link); order ref → sales-order card.
  Actionable rows (shortfall, then outstanding) sort first.
- **Serial detail** (`serial_card.php`) — one serial's back-half: Manufactured
  (traced by lot) → Shipped → Under Warranty → Support Ended, plus context.
- **Slim summary** on the project main card → links into the tab.

## ATP / shortfall formula (per product, company-wide)

```
on_hand   = Σ product_stock.reel  (all warehouses)
incoming  = Σ mrp_mo.qty          (in-progress MOs, status = 2)
committed = Σ per-line max(0, ordered − shipped) over open sales orders
            (validated by default; within SERIALTRACKER_ATP_WINDOW_DAYS)
shortfall = max(0, committed − on_hand − incoming)
```
Manufactured (active `bom_bom`) → Create MO; else → Reorder (PO).

Settings (Home → Setup → Serial Tracker): debug, "count draft orders as demand",
demand time window (days). Warehouse scope = all.

## Verified linkage (llxiw_ prefix on staging)

- project → shipments: `expedition.fk_projet`
- project → order lines: `commande.fk_projet` → `commandedet`
- shipment line → order line: `expeditiondet.element_type='commande'` + `fk_elementdet`
- shipment line → serial: `expeditiondet_batch.batch`
- serial → lot: `product_lot.batch` + `fk_product` → `product_lot.rowid`
- shipment status: `expedition.fk_statut` (0 draft, ≥1 shipped)
- serialized product: `product.tobatch > 0`

## ⚠️ PENDING INTEGRATION — pick up here next pass

1. **Returns / warranty (module being overhauled).**
   `class/serialwarrantyadapter.class.php::forSerial()` returns `null` (deferred)
   — the back-half **Under Warranty / Support Ended** steps show "integration
   pending". Nothing else touches `llxiw_svc_warranty` / `llxiw_customer_return*`.
   When the returns/warranty overhaul lands, implement that one method (known
   schema documented inside it). **Also:** "shipped"/"committed" currently count
   raw shipment qty and do NOT net returns — a returned-not-yet-reshipped unit is
   overstated as delivered until returns netting is added here.

## Dependencies (soft / runtime)

- **DoliBulkPO** (module `bulkpo`) — *optional.* When installed:
  - **Per-line Reorder** deep-links into the Bulk PO wizard pre-seeded with that
    product + shortfall qty.
  - **Multi-select → one PO:** each purchased-short line gets a checkbox; ticking
    several and clicking **Create PO from selected (N)** seeds the wizard with all
    of them at once. Vendor grouping is the employee's call — tick lines for the
    same vendor; they pick the vendor in the wizard (which creates one PO).
  - Both use `bulkpo_wizard.php?seed=<base64 JSON [{id,qty}]>`, which `bulkpo.js`
    merges into its staging store.

  When `bulkpo` is **not** enabled, the checkboxes/batch bar are hidden and
  per-line Reorder **gracefully falls back** to the product's supplier tab
  (`product/fournisseurs.php?id=`). Runtime check (`isModEnabled('bulkpo')`), NOT a
  hard `$this->depends` — serialtracker installs and works without BulkPO. Requires
  DoliBulkPO ≥ the version that added the `seed` param.

## Build / install

```
python3 bin/build.py <version>
```
Then Home → Setup → Modules → Deploy/install external module.
