# Breax Technologies: Invoice Page Design Brief

**Task for the AI agent:** Restyle the existing invoice page so it matches this design exactly. Keep the existing data and backend logic. Change only the markup and styling. Use the template at the bottom as the source of truth and bind real invoice data to the `{{placeholders}}`.

## Brand tokens

| Token | Value | Used for |
|---|---|---|
| Primary | `#664aae` | Top bar, table header, section headings, description row tints |
| Secondary | `#13a2db` | Rule under the header, underline beneath "From" and "Billed to" headings |
| Accent | `#141d38` | "Invoice" title, body text, "Unpaid" pill, footer background |
| Row tint A | `#ece8f7` | Odd description cells |
| Row tint B | `#f7f5fc` | Even description cells |
| Total row | `#e3f4fb` | Background of the "Total due" row |
| Line | `#ddd9ec` | Row dividers |
| Muted text | `#5d6480` | Secondary text |
| Page background | `#eceaf4` | Area around the sheet |

Dark mode is supported through `prefers-color-scheme`. The header stays white in both modes so the logo always sits on white.

## Typography

- Headings and the "Invoice" title: Georgia, "Times New Roman", serif.
- Everything else (tables, labels, addresses): Arial, Helvetica, sans-serif.
- Title size 1.9rem. Table text .92rem. Notes .88rem.

## Layout (top to bottom)

1. **Header:** white band, 8px primary top border, 3px secondary bottom border, logo centred (max width 340px).
2. **Title bar:** left side has "Invoice" (accent colour) with the invoice number underneath in muted text. Right side has a small "Status" label and a pill badge.
3. **Dates row:** issue date and due date only. Bold date on top, label underneath.
4. **Parties:** "From" and "Billed to" side by side, each with a primary-coloured heading and a secondary underline.
5. **Items table:** exactly three columns: **Description**, **Quantity**, **Amount**.
   - Header row is solid primary with white text.
   - Description cells alternate between row tint A and row tint B. Each description has a bold title and a muted one-line subtitle.
   - No left border on the description cells.
   - Below the rows: Subtotal, VAT (16%), and **Total due**. The total row has the light blue background, bold text and a 2px primary top border.
6. **Notes:** two columns, "Payment details" and "Terms".
7. **Footer:** accent-coloured band with white centred text.

## Status pill

The pill is rounded, bold, white text, with a small white dot on the left. Set `data-status` from the invoice's real status:

| Status | Colour |
|---|---|
| unpaid | Accent `#141d38` |
| draft | `#7a7f99` |
| sent, partial | Secondary `#13a2db` |
| paid | `#1f9d62` |
| overdue | `#d6404e` |

In dark mode the unpaid pill gets a 1px `#4a5688` outline, because the accent colour is almost the same as the dark background.

## Rules

- Do not add columns (no Rate, no unit price).
- Do not add a status dropdown. The status is display only and comes from the data.
- Right-align the Quantity and Amount columns.
- Keep it responsive. The table scrolls sideways inside its own container on narrow screens.
- Print must keep the colours (`print-color-adjust: exact`).
- Use the logo file as a regular image asset: `{{LOGO_URL}}`.

## Reference template

```html
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Invoice {{INVOICE_NUMBER}} | Breax Technologies</title>
<style>
:root{
  box-sizing:border-box;
  padding-top:env(safe-area-inset-top,0px);
  padding-bottom:env(safe-area-inset-bottom,0px);
  --primary:#664aae; --secondary:#13a2db; --accent:#141d38;
  --ring:transparent; --page:#eceaf4; --paper:#ffffff; --ink:#141d38; --muted:#5d6480; --line:#ddd9ec;
  --row-a:#ece8f7; --row-b:#f7f5fc; --total:#e3f4fb; --head-bg:#ffffff;
}
@media (prefers-color-scheme: dark){
  :root:not([data-theme="light"]){
    --ring:#4a5688; --page:#0d1224; --paper:#171f3a; --ink:#e8eaf5; --muted:#a3a9c6; --line:#2b3558;
    --row-a:#232b52; --row-b:#1c2445; --total:#10394b;
  }
}
:root[data-theme="dark"]{
  --ring:#4a5688; --page:#0d1224; --paper:#171f3a; --ink:#e8eaf5; --muted:#a3a9c6; --line:#2b3558;
  --row-a:#232b52; --row-b:#1c2445; --total:#10394b;
}
*{box-sizing:border-box}
html{scroll-padding-top:env(safe-area-inset-top,0px)}
body{margin:0;background:var(--page);color:var(--ink);font-family:Georgia,"Times New Roman",serif;line-height:1.5;padding:24px 12px}
.sheet{max-width:840px;margin:0 auto;background:var(--paper);border-radius:6px;box-shadow:0 6px 28px rgba(20,29,56,.15);overflow:hidden}
header{background:var(--head-bg);border-top:8px solid var(--primary);border-bottom:3px solid var(--secondary);padding:24px 40px;text-align:center}
header img{display:block;margin:0 auto;width:min(100%,340px);height:auto}
.titlebar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;padding:24px 40px 0;font-family:Arial,Helvetica,sans-serif}
.titlebar h1{margin:0;font-family:Georgia,serif;font-size:1.9rem;color:var(--ink)}
.titlebar .num{color:var(--muted);font-size:.9rem}
.status-box{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.status-label{font-size:.85rem;color:var(--muted)}
.badge{display:inline-flex;align-items:center;gap:8px;padding:7px 16px;border-radius:999px;font-weight:700;font-size:.9rem;color:#fff;background:#8a8fa8}
.badge::before{content:"";width:9px;height:9px;border-radius:50%;background:#fff;opacity:.9}
.badge[data-status="draft"]{background:#7a7f99}
.badge[data-status="unpaid"]{background:var(--accent);box-shadow:0 0 0 1px var(--ring)}
.badge[data-status="sent"],.badge[data-status="partial"]{background:var(--secondary)}
.badge[data-status="paid"]{background:#1f9d62}
.badge[data-status="overdue"]{background:#d6404e}
main{padding:24px 40px 32px}
.dates{display:flex;gap:32px;flex-wrap:wrap;font-family:Arial,Helvetica,sans-serif;font-size:.9rem;color:var(--muted);margin-bottom:24px}
.dates b{display:block;color:var(--ink)}
.parties{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:24px;margin-bottom:32px;font-family:Arial,Helvetica,sans-serif;font-size:.92rem}
.parties h2{margin:0 0 6px;font-size:.95rem;color:var(--primary);border-bottom:2px solid var(--secondary);padding-bottom:4px}
.parties p{margin:0;color:var(--muted)}
.parties p b{color:var(--ink)}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-family:Arial,Helvetica,sans-serif;font-size:.92rem;min-width:560px}
thead th{background:var(--primary);color:#fff;text-align:left;padding:12px 14px;font-weight:600}
th.num,td.num{text-align:right;white-space:nowrap}
tbody td{padding:14px;vertical-align:top;border-bottom:1px solid var(--line)}
tbody tr:nth-child(odd) td.desc{background:var(--row-a)}
tbody tr:nth-child(even) td.desc{background:var(--row-b)}
td.desc strong{display:block}
td.desc span{color:var(--muted);font-size:.85rem}
tfoot td{padding:10px 14px;text-align:right}
tfoot tr.sub td:first-child,tfoot tr.tax td:first-child{color:var(--muted)}
tfoot tr.grand td{background:var(--total);font-size:1.1rem;font-weight:700;border-top:2px solid var(--primary)}
.notes{margin-top:32px;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:24px;font-family:Arial,Helvetica,sans-serif;font-size:.88rem;color:var(--muted)}
.notes h3{margin:0 0 6px;font-size:.95rem;color:var(--ink)}
footer{background:var(--accent);color:#fff;padding:16px 40px;text-align:center;font-family:Arial,Helvetica,sans-serif;font-size:.85rem}
@media (max-width:600px){header,main,footer,.titlebar{padding-left:20px;padding-right:20px}}
@media print{body{background:#fff;padding:0}.sheet{box-shadow:none}*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
</head>
<body>
<div class="sheet">
  <header>
    <img src="{{LOGO_URL}}" alt="Breax Technologies Limited logo">
  </header>

  <div class="titlebar">
    <div>
      <h1>Invoice</h1>
      <div class="num">{{INVOICE_NUMBER}}</div>
    </div>
    <div class="status-box">
      <span class="status-label">Status</span>
      <!-- data-status: draft | unpaid | sent | partial | paid | overdue -->
      <span class="badge" data-status="{{STATUS_KEY}}" role="status">{{STATUS_LABEL}}</span>
    </div>
  </div>

  <main>
    <div class="dates">
      <div><b>{{ISSUE_DATE}}</b>Issue date</div>
      <div><b>{{DUE_DATE}}</b>Due date</div>
    </div>

    <section class="parties">
      <div>
        <h2>From</h2>
        <p><b>{{SENDER_NAME}}</b><br>{{SENDER_ADDRESS}}<br>{{SENDER_EMAIL}}</p>
      </div>
      <div>
        <h2>Billed to</h2>
        <p><b>{{CLIENT_NAME}}</b><br>{{CLIENT_CONTACT}}<br>{{CLIENT_ADDRESS}}<br>{{CLIENT_EMAIL}}</p>
      </div>
    </section>

    <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Description</th><th class="num">Quantity</th><th class="num">Amount</th></tr>
      </thead>
      <tbody>
        <!-- repeat one row per line item -->
        <tr>
          <td class="desc"><strong>{{ITEM_TITLE}}</strong><span>{{ITEM_SUBTITLE}}</span></td>
          <td class="num">{{ITEM_QUANTITY}}</td>
          <td class="num">{{ITEM_AMOUNT}}</td>
        </tr>
      </tbody>
      <tfoot>
        <tr class="sub"><td colspan="2" class="num">Subtotal</td><td class="num">{{SUBTOTAL}}</td></tr>
        <tr class="tax"><td colspan="2" class="num">VAT (16%)</td><td class="num">{{VAT}}</td></tr>
        <tr class="grand"><td colspan="2" class="num">Total due</td><td class="num">{{TOTAL}}</td></tr>
      </tfoot>
    </table>
    </div>

    <section class="notes">
      <div>
        <h3>Payment details</h3>
        {{PAYMENT_DETAILS}}
      </div>
      <div>
        <h3>Terms</h3>
        {{TERMS}}
      </div>
    </section>
  </main>

  <footer>Thank you for your business.</footer>
</div>
</body>
</html>
```

---

# Paid invoice template (variant)

Use the reference template above, with these changes, when the invoice status is **paid**.

1. Set the pill to `data-status="paid"` with the label "Paid" (green `#1f9d62`).
2. Add a **Balance** row at the bottom of the items table, directly under "Total due". Same layout as the other footer rows: bold text, thin `--line` top border, and the value in green (`#1f9d62`). Placeholder: `{{BALANCE}}`.
3. Add a **Transactions** section between the items table and the notes. It has a heading in primary colour with a secondary underline (same style as "From" and "Billed to"), followed by a three-column table: **Date**, **Payment method**, **Transaction code**. The header row is solid primary with white text, and the body rows alternate between row tint A and row tint B across every cell. Transaction codes are bold with slight letter spacing. Repeat one row per payment.

Extra CSS:

```css
.transactions{margin-top:32px;font-family:Arial,Helvetica,sans-serif}
.transactions h2{margin:0 0 10px;font-size:.95rem;color:var(--primary);border-bottom:2px solid var(--secondary);padding-bottom:4px}
.tx{min-width:460px}
.tx tbody tr:nth-child(odd) td{background:var(--row-a)}
.tx tbody tr:nth-child(even) td{background:var(--row-b)}
.tx td.code{font-weight:700;letter-spacing:.04em}
tfoot tr.balance td{font-weight:700;border-top:1px solid var(--line)}
tfoot tr.balance td.num:last-child{color:#1f9d62}
```

Extra markup:

```html
<tr class="balance"><td colspan="2" class="num">Balance</td><td class="num">{{BALANCE}}</td></tr>
<!-- goes inside <tfoot>, after the "Total due" row -->

<section class="transactions">
  <h2>Transactions</h2>
  <div class="table-wrap">
  <table class="tx">
    <thead><tr><th>Date</th><th>Payment method</th><th>Transaction code</th></tr></thead>
    <tbody>
      <!-- repeat one row per payment -->
      <tr><td>{{TX_DATE}}</td><td>{{TX_METHOD}}</td><td class="code">{{TX_CODE}}</td></tr>
    </tbody>
  </table>
  </div>
</section>
```

---

# When to show the Transactions table

- **Unpaid (no payments recorded):** do not render the Transactions section or the Balance row. Remove them from the HTML entirely. Do not hide them with CSS and do not leave an empty table.
- **Partially paid or Paid (at least one payment recorded):** render both the Transactions section and the Balance row.

Base the decision on the real payment data (one or more recorded payments), not on the status label alone. If the data and the status disagree, flag it before changing anything.

**Balance** = Total due minus the sum of all recorded payments.

- Paid: Balance is 0 and is shown in green (`#1f9d62`).
- Partially paid: Balance is the remaining amount in the normal text colour (not green).

**Pill:** `data-status="paid"` for Paid, `data-status="partial"` for Partially paid, `data-status="unpaid"` for Unpaid.

**Data mapping for each transaction row**

- Date: the payment date, formatted like "18 September 2026".
- Payment method: for example "M-Pesa" or "Bank transfer".
- Transaction code: the reference or receipt code, exactly as stored.
- If a value is missing, show "-". Never invent values.
- List payments oldest first, one row per payment, with rows alternating between the two row tints.
