<style>
  .rbi-page {
    --rbi-bg: #f5f3fb; --rbi-card: #ffffff; --rbi-ink: #1c1733; --rbi-muted: #6b6784;
    --rbi-line: #e6e2f2; --rbi-brand: #5b3fa3; --rbi-brand-soft: #efeafb; --rbi-radius: 16px;
    --rbi-c1: #5b3fa3; --rbi-c2: #2f9bd6; --rbi-c3: #e8943a; --rbi-c4: #2fa77a; --rbi-c5: #d85a8a; --rbi-c6: #8a8fa3; --rbi-c7: #b8a21f;
    margin: 0 -24px; padding: 1px 0; background: var(--rbi-bg); color: var(--rbi-ink);
    font-family: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.5;
  }
  @media (prefers-color-scheme: dark) {
    .rbi-page { --rbi-bg: #14111f; --rbi-card: #1d1930; --rbi-ink: #f0edfa; --rbi-muted: #a29fba; --rbi-line: #2e2947; --rbi-brand: #a58bf0; --rbi-brand-soft: #2a2347; }
  }
  .rbi-page *, .rbi-page *::before, .rbi-page *::after { box-sizing: border-box; }
  .rbi-wrap { max-width: 1072px; margin: 0 auto; padding: 0 25px 0px; }
  .rbi-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
  .rbi-head h1 { margin: 0; font-size: 1.5rem; letter-spacing: -.01em; }
  .rbi-head p { margin: 4px 0 0; color: var(--rbi-muted); font-size: .92rem; }
  .rbi-asof { font-size: .8rem; color: var(--rbi-muted); background: var(--rbi-card); border: 1px solid var(--rbi-line); padding: 6px 12px; border-radius: 999px; }
  .rbi-card { min-width: 0; background: var(--rbi-card); border: 1px solid var(--rbi-line); border-radius: var(--rbi-radius); padding: 20px; margin-bottom: 20px; }
  .rbi-card h2 { margin: 0 0 4px; font-size: 1.05rem; }
  .rbi-card .rbi-sub { margin: 0 0 16px; color: var(--rbi-muted); font-size: .85rem; }
  .rbi-cat { display: grid; grid-template-columns: 150px minmax(0, 1fr) auto; align-items: center; gap: 12px; padding: 8px 0; }
  .rbi-cat .rbi-name { min-width: 0; font-size: .9rem; font-weight: 600; display: flex; align-items: center; gap: 8px; overflow-wrap: anywhere; }
  .rbi-dot { width: 10px; height: 10px; border-radius: 50%; flex: none; }
  .rbi-track { height: 10px; min-width: 0; background: var(--rbi-brand-soft); border-radius: 999px; overflow: hidden; }
  .rbi-fill { height: 100%; border-radius: 999px; transition: width .5s ease; }
  .rbi-cat .rbi-amt { font-size: .9rem; font-weight: 700; white-space: nowrap; text-align: right; }
  .rbi-cat .rbi-pct { font-weight: 500; color: var(--rbi-muted); font-size: .78rem; margin-left: 6px; }
  .rbi-controls { display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; align-items: center; margin-bottom: 14px; }
  .rbi-chips { display: flex; flex-wrap: wrap; gap: 8px; }
  .rbi-chip { border: 1px solid var(--rbi-line); background: var(--rbi-card); color: var(--rbi-ink); padding: 6px 14px; border-radius: var(--r-pill); font-size: .82rem; cursor: pointer; font-family: inherit; }
  .rbi-chip.active { background: var(--rbi-brand); border-color: var(--rbi-brand); color: #fff; }
  .rbi-page select { border: 1px solid var(--rbi-line); background: var(--rbi-card); color: var(--rbi-ink); padding: 7px 12px; border-radius: 10px; font-size: .85rem; font-family: inherit; }
  .rbi-page table { width: 100%; border-collapse: collapse; }
  .rbi-page thead th { text-align: left; font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: var(--rbi-muted); padding: 10px 12px; border-bottom: 1px solid var(--rbi-line); }
  .rbi-page th.num, .rbi-page td.num { text-align: right; }
  .rbi-page tbody td { padding: 14px 12px; border-bottom: 1px solid var(--rbi-line); font-size: .9rem; vertical-align: middle; }
  .rbi-page tbody tr:last-child td { border-bottom: 0; }
  .rbi-item { font-weight: 600; }
  .rbi-item small { display: block; color: var(--rbi-muted); font-weight: 400; font-size: .78rem; }
  .rbi-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: .72rem; font-weight: 600; }
  .rbi-b-one { background: #e9f1fb; color: #2467a8; }
  .rbi-b-year { background: #efeafb; color: #5b3fa3; }
  .rbi-b-month { background: #e5f6ee; color: #1f7d57; }
  @media (prefers-color-scheme: dark) {
    .rbi-b-one { background: #1b2c44; color: #8cc0f2; }
    .rbi-b-year { background: #2a2347; color: #c3b0f7; }
    .rbi-b-month { background: #173a2c; color: #7fd8ae; }
  }
  .rbi-collected { font-weight: 700; }
  .rbi-mini { height: 6px; width: 100%; max-width: 120px; background: var(--rbi-brand-soft); border-radius: 999px; overflow: hidden; margin-top: 6px; margin-left: auto; }
  .rbi-mini span { display: block; height: 100%; background: var(--rbi-brand); border-radius: 999px; }
  .rbi-empty { text-align: center; color: var(--rbi-muted); padding: 32px 12px; font-size: .9rem; }
  .rbi-note { color: var(--rbi-muted); font-size: .78rem; margin-top: 12px; }
  .rbi-error { padding: 14px 16px; border: 1px solid #e8a4a8; border-radius: 12px; color: #9e2630; background: #fff1f1; }
  @media (max-width: 720px) {
    .rbi-page { margin: 0 -20px; }
    .rbi-cat { grid-template-columns: minmax(0, 1fr) auto; }
    .rbi-cat .rbi-track { grid-column: 1 / -1; order: 3; }
    .rbi-page thead { display: none; }
    .rbi-page table, .rbi-page tbody, .rbi-page tr, .rbi-page td { display: block; width: 100%; }
    .rbi-page tbody tr { border: 1px solid var(--rbi-line); border-radius: 12px; padding: 12px; margin-bottom: 12px; }
    .rbi-page tbody td { border: 0; padding: 6px 0; display: flex; justify-content: space-between; gap: 12px; text-align: right; }
    .rbi-page tbody td::before { content: attr(data-label); color: var(--rbi-muted); font-size: .78rem; text-align: left; }
    .rbi-page tbody td.rbi-item-cell { display: block; text-align: left; padding-bottom: 8px; }
    .rbi-page tbody td.rbi-item-cell::before { content: none; }
    .rbi-mini { margin-left: 0; }
  }
  @media (max-width: 560px) { .rbi-page { margin: 0 -16px; } }
</style>
<div class="rbi-page">
  <div class="rbi-wrap">
    <header class="rbi-head">
      <div>
        <h1>Revenue by Item</h1>
        <p>What has brought in money from issued invoices.</p>
      </div>
      <span class="rbi-asof">As of <span id="rbi-asof"><?= e(date('j M Y', strtotime($revenueData['as_of_date']))) ?></span></span>
    </header>

    <?php if ($revenueDataError !== ''): ?>
      <p class="rbi-error" role="alert">Revenue by item could not be calculated: <?= e($revenueDataError) ?></p>
    <?php else: ?>
      <section class="rbi-card">
        <h2>By category</h2>
        <p class="rbi-sub">Share of collected revenue from each service category.</p>
        <div id="rbi-categories"></div>
      </section>

      <section class="rbi-card">
        <h2>By catalogue item</h2>
        <p class="rbi-sub">Only items that have collected payments are listed.</p>
        <div class="rbi-controls">
          <div class="rbi-chips" id="rbi-chips"></div>
          <select id="rbi-sort" aria-label="Sort items">
            <option value="revenue">Highest revenue</option>
            <option value="name">Item name (A–Z)</option>
            <option value="invoices">Most invoices</option>
          </select>
        </div>
        <table>
          <thead>
            <tr><th>Item</th><th>Billing</th><th class="num">Invoices</th><th class="num">Qty sold</th><th class="num">Collected (KES)</th></tr>
          </thead>
          <tbody id="rbi-rows"></tbody>
        </table>
        <div class="dt-foot" id="rbi-table-footer">
          <div class="dt-foot-left">
            <div class="dt-count" id="rbi-count" aria-live="polite"></div>
            <label class="dt-rpp">Rows per page <span class="dt-field"><select class="dt-select" id="rbi-page-size" aria-label="Rows per page"><option value="10" selected>10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option><option value="250">250</option></select><span class="dt-icon-right"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></span></span></label>
          </div>
          <nav class="dt-pager" id="rbi-pagination" aria-label="Pagination"></nav>
        </div>
        <div class="rbi-empty" id="rbi-empty" hidden>No revenue collected for this selection yet.</div>
        <p class="rbi-note">Collected = payments received against invoices that include the item. Unpaid and outstanding amounts are excluded.</p>
      </section>
    <?php endif; ?>
  </div>
</div>
<?php if ($revenueDataError === ''): ?>
<script type="application/json" id="rbi-data"><?= json_encode($revenueData['items'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></script>
<script>
(() => {
  const DATA = JSON.parse(document.getElementById("rbi-data").textContent);
  const COLORS = ["var(--rbi-c1)","var(--rbi-c2)","var(--rbi-c3)","var(--rbi-c4)","var(--rbi-c5)","var(--rbi-c6)","var(--rbi-c7)"];
  const fmt = n => new Intl.NumberFormat("en-KE", { maximumFractionDigits: 0 }).format(n);
  const badgeClass = billing => billing === "Yearly" ? "rbi-b-year" : billing === "Monthly" ? "rbi-b-month" : "rbi-b-one";
  const earning = DATA.filter(item => item.collected > 0);
  const total = earning.reduce((sum, item) => sum + item.collected, 0);
  const catMap = new Map();
  earning.forEach(item => catMap.set(item.category, (catMap.get(item.category) || 0) + item.collected));
  const cats = [...catMap.entries()].sort((a, b) => b[1] - a[1]);
  const catColor = new Map(cats.map(([category], index) => [category, COLORS[index % COLORS.length]]));
  const categoriesEl = document.getElementById("rbi-categories");
  cats.forEach(([category, amount]) => {
    const pct = total ? (amount / total) * 100 : 0;
    const row = document.createElement("div");
    row.className = "rbi-cat";
    const name = document.createElement("div");
    name.className = "rbi-name";
    const dot = document.createElement("span");
    dot.className = "rbi-dot";
    dot.style.background = catColor.get(category);
    dot.setAttribute("aria-hidden", "true");
    name.append(dot, document.createTextNode(category));
    const track = document.createElement("div");
    track.className = "rbi-track";
    const fill = document.createElement("div");
    fill.className = "rbi-fill";
    fill.style.width = `${pct.toFixed(1)}%`;
    fill.style.background = catColor.get(category);
    track.append(fill);
    const value = document.createElement("div");
    value.className = "rbi-amt";
    value.append(document.createTextNode(`KES ${fmt(amount)}`));
    const share = document.createElement("span");
    share.className = "rbi-pct";
    share.textContent = `${pct.toFixed(0)}%`;
    value.append(share);
    row.append(name, track, value);
    categoriesEl.append(row);
  });

  let activeCat = "All";
  const chipsEl = document.getElementById("rbi-chips");
  const rowsEl = document.getElementById("rbi-rows");
  const sortEl = document.getElementById("rbi-sort");
  const tableFooter = document.getElementById("rbi-table-footer");
  const countEl = document.getElementById("rbi-count");
  const pageSizeEl = document.getElementById("rbi-page-size");
  const paginationEl = document.getElementById("rbi-pagination");
  let page = 1;
  let pageSize = Number(pageSizeEl.value) || 10;
  function renderChips() {
    chipsEl.replaceChildren();
    ["All", ...cats.map(([category]) => category)].forEach(category => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = `rbi-chip${category === activeCat ? " active" : ""}`;
      button.dataset.cat = category;
      button.setAttribute("aria-pressed", category === activeCat ? "true" : "false");
      button.textContent = category;
      chipsEl.append(button);
    });
  }
  chipsEl.addEventListener("click", event => {
    const button = event.target.closest(".rbi-chip");
    if (!button) return;
    activeCat = button.dataset.cat;
    page = 1;
    renderChips();
    renderRows();
  });

  function addPageButton(label, targetPage, disabled, current, iconDirection) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "dt-page";
    button.disabled = disabled;
    if (current) {
      button.setAttribute("aria-current", "page");
      button.setAttribute("aria-label", `Page ${label}, current page`);
    } else if (/^\d+$/.test(label)) {
      button.setAttribute("aria-label", `Go to page ${label}`);
    } else if (!disabled) {
      button.setAttribute("aria-label", label);
    }
    if (iconDirection) {
      const icon = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      icon.setAttribute("width", "16");
      icon.setAttribute("height", "16");
      icon.setAttribute("viewBox", "0 0 24 24");
      icon.setAttribute("fill", "none");
      icon.setAttribute("stroke", "currentColor");
      icon.setAttribute("stroke-width", "2");
      icon.setAttribute("stroke-linecap", "round");
      icon.setAttribute("stroke-linejoin", "round");
      icon.setAttribute("aria-hidden", "true");
      const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
      path.setAttribute("d", iconDirection === "previous" ? "M15 6l-6 6 6 6" : "M9 6l6 6-6 6");
      icon.append(path);
      if (iconDirection === "previous") button.append(icon);
      button.append(document.createTextNode(label));
      if (iconDirection === "next") button.append(icon);
    } else {
      button.textContent = label;
    }
    button.addEventListener("click", () => {
      page = targetPage;
      renderRows();
    });
    paginationEl.append(button);
  }

  function renderRows() {
    const sort = sortEl.value;
    const list = earning.filter(item => activeCat === "All" || item.category === activeCat);
    list.sort((a, b) => sort === "name" ? a.name.localeCompare(b.name) :
      sort === "invoices" ? b.invoices - a.invoices || b.collected - a.collected :
      b.collected - a.collected);
    const pageCount = Math.max(1, Math.ceil(list.length / pageSize));
    page = Math.min(page, pageCount);
    const start = (page - 1) * pageSize;
    const visibleList = list.slice(start, start + pageSize);
    const max = Math.max(...list.map(item => item.collected), 1);
    rowsEl.replaceChildren();
    visibleList.forEach(item => {
      const row = document.createElement("tr");
      const itemCell = document.createElement("td");
      itemCell.className = "rbi-item-cell";
      const itemName = document.createElement("div");
      itemName.className = "rbi-item";
      itemName.append(document.createTextNode(item.name));
      const categoryName = document.createElement("small");
      categoryName.textContent = item.category;
      itemName.append(categoryName);
      itemCell.append(itemName);

      const billingCell = document.createElement("td");
      billingCell.dataset.label = "Billing";
      const badge = document.createElement("span");
      badge.className = `rbi-badge ${badgeClass(item.billing)}`;
      badge.textContent = item.billing;
      billingCell.append(badge);

      const invoiceCell = document.createElement("td");
      invoiceCell.className = "num";
      invoiceCell.dataset.label = "Invoices";
      invoiceCell.textContent = fmt(item.invoices);

      const qtyCell = document.createElement("td");
      qtyCell.className = "num";
      qtyCell.dataset.label = "Qty sold";
      qtyCell.textContent = fmt(item.qty);

      const collectedCell = document.createElement("td");
      collectedCell.className = "num";
      collectedCell.dataset.label = "Collected (KES)";
      const collectedAmount = document.createElement("span");
      collectedAmount.className = "rbi-collected";
      collectedAmount.textContent = fmt(item.collected);
      const mini = document.createElement("div");
      mini.className = "rbi-mini";
      const miniFill = document.createElement("span");
      miniFill.style.width = `${(item.collected / max * 100).toFixed(0)}%`;
      mini.append(miniFill);
      collectedCell.append(collectedAmount, mini);
      row.append(itemCell, billingCell, invoiceCell, qtyCell, collectedCell);
      rowsEl.append(row);
    });
    tableFooter.hidden = list.length === 0;
    countEl.innerHTML = list.length
      ? `Showing <b>${start + 1}–${start + visibleList.length}</b> of <b>${list.length}</b> items`
      : "No items to show.";
    paginationEl.replaceChildren();
    addPageButton("Previous", Math.max(1, page - 1), page === 1, false, "previous");
    if (pageCount <= 7) {
      for (let number = 1; number <= pageCount; number += 1) {
        addPageButton(String(number), number, false, number === page);
      }
    } else {
      const pageNumbers = new Set([1, pageCount, page - 1, page, page + 1]);
      if (page <= 3) [2, 3, 4].forEach(number => pageNumbers.add(number));
      if (page >= pageCount - 2) [pageCount - 3, pageCount - 2, pageCount - 1].forEach(number => pageNumbers.add(number));
      const orderedPages = [...pageNumbers].filter(number => number >= 1 && number <= pageCount).sort((left, right) => left - right);
      let previousNumber = 0;
      orderedPages.forEach(number => {
        if (previousNumber && number - previousNumber > 1) {
          const ellipsis = document.createElement("span");
          ellipsis.className = "dt-page dt-ellipsis";
          ellipsis.setAttribute("aria-hidden", "true");
          ellipsis.textContent = "…";
          paginationEl.append(ellipsis);
        }
        addPageButton(String(number), number, false, number === page);
        previousNumber = number;
      });
    }
    addPageButton("Next", Math.min(pageCount, page + 1), page === pageCount, false, "next");
    document.getElementById("rbi-empty").hidden = list.length > 0;
  }
  sortEl.addEventListener("change", () => { page = 1; renderRows(); });
  pageSizeEl.addEventListener("change", () => {
    pageSize = Math.max(1, Number(pageSizeEl.value) || 10);
    page = 1;
    renderRows();
  });
  renderChips();
  renderRows();
})();
</script>
<?php endif; ?>
