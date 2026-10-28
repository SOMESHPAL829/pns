(function () {
  const DEFAULT_SIZE = 15;
  const normalizedHeading = value => String(value || "").replace(/[^a-z]/gi, "").toLowerCase();

  function pageItems(total, current) {
    if (total <= 7) return Array.from({ length: total }, (_, index) => index + 1);
    const values = new Set([1, total, current - 1, current, current + 1]);
    if (current <= 3) [2, 3, 4].forEach(value => values.add(value));
    if (current >= total - 2) [total - 3, total - 2, total - 1].forEach(value => values.add(value));
    const pages = Array.from(values).filter(value => value > 0 && value <= total).sort((a, b) => a - b), output = [];
    pages.forEach(function (page, index) { if (index && page - pages[index - 1] > 1) output.push("ellipsis-" + page); output.push(page); });
    return output;
  }

  function enhance(table, index) {
    const scroll = table.closest(".data-scroll");
    if (!scroll || scroll.nextElementSibling?.classList.contains("table-pagination")) return;
    const headerRow = Array.from(table.tHead?.rows || []).find(row => !row.classList.contains("col-filter-row"));
    if (!headerRow) return;
    const state = { page: 1, size: DEFAULT_SIZE, sortIndex: 0, sortDirection: "descending", injectedSerial: false };
    let serialHeader = Array.from(headerRow.cells).find(cell => normalizedHeading(cell.textContent) === "srno");
    if (serialHeader) serialHeader.textContent = "SR. No.";
    else {
      serialHeader = document.createElement("th"); serialHeader.textContent = "SR. No."; serialHeader.className = "table-serial-heading"; headerRow.prepend(serialHeader); state.injectedSerial = true;
      Array.from(table.tHead.rows).filter(row => row !== headerRow).forEach(function (row) { const cell = document.createElement("th"); cell.className = "table-serial-filter"; row.prepend(cell); });
    }
    state.sortIndex = serialHeader.cellIndex;

    const pagination = document.createElement("div"); pagination.className = "table-pagination"; pagination.setAttribute("aria-label", "Table pagination");
    const summary = document.createElement("div"); summary.className = "table-pagination-summary";
    const controls = document.createElement("div"); controls.className = "table-pagination-controls";
    const sizeLabel = document.createElement("label"); sizeLabel.className = "table-page-size"; sizeLabel.append(document.createTextNode("Rows per page"));
    const sizeSelect = document.createElement("select"); sizeSelect.setAttribute("aria-label", "Rows per page");
    [15, 25, 50, 100].forEach(value => sizeSelect.add(new Option(String(value), String(value), value === DEFAULT_SIZE, value === DEFAULT_SIZE))); sizeLabel.appendChild(sizeSelect);
    const navigation = document.createElement("nav"); navigation.className = "table-page-navigation"; navigation.setAttribute("aria-label", "Pagination for table " + (index + 1));
    controls.append(sizeLabel, navigation); pagination.append(summary, controls); scroll.after(pagination);

    const sourceRows = () => Array.from(table.tBodies).flatMap(body => Array.from(body.rows));
    const isDataRow = row => row.cells.length > 1 && !Array.from(row.cells).some(cell => Number(cell.colSpan) > 1);

    function cleanActionCells(row) {
      Array.from(headerRow.cells).forEach(function (heading, cellIndex) {
        if (!/^actions?$/.test(normalizedHeading(heading.textContent))) return;
        const cell = row.cells[cellIndex]; if (!cell) return;
        Array.from(cell.childNodes).forEach(function (node) { if (node.nodeType === Node.TEXT_NODE && /^[\s—-]*$/.test(node.textContent)) node.remove(); });
      });
    }

    function prepareRows() {
      const expected = headerRow.cells.length, rows = sourceRows();
      rows.forEach(function (row) {
        if (state.injectedSerial && !row.querySelector(":scope > .table-serial-cell")) {
          if (row.cells.length === 1 && Number(row.cells[0].colSpan) > 1) row.cells[0].colSpan = expected;
          else if (row.cells.length === expected - 1) { const serial = document.createElement("td"); serial.className = "table-serial-cell mono"; row.prepend(serial); }
        }
        cleanActionCells(row);
      });
      const dataRows = rows.filter(isDataRow);
      dataRows.forEach(function (row, rowIndex) { if (!row.dataset.tableOrder) row.dataset.tableOrder = String(dataRows.length - rowIndex); });
      let serial = 0;
      dataRows.forEach(function (row) { serial += 1; if (row.cells[0] && row.cells[0].textContent !== String(serial)) row.cells[0].textContent = String(serial); });
    }

    function cellValue(row, cellIndex) {
      if (cellIndex === state.sortIndex) return row.dataset.tableOrder || "0";
      const cell = row.cells[cellIndex]; if (!cell) return "";
      const checkbox = cell.querySelector('input[type="checkbox"]'); if (checkbox) return checkbox.checked ? "1" : "0";
      return (cell.dataset.sortValue || cell.innerText || "").replace(/\s+/g, " ").trim();
    }

    function compareValues(left, right) {
      const numeric = /^[₹$€£]?\s*-?[\d,]+(?:\.\d+)?\s*%?$/;
      if (numeric.test(left) && numeric.test(right)) return Number(left.replace(/[^\d.-]/g, "")) - Number(right.replace(/[^\d.-]/g, ""));
      const date = /^\d{4}-\d{2}-\d{2}(?:[ T].*)?$/;
      if (date.test(left) && date.test(right)) return Date.parse(left) - Date.parse(right);
      return left.localeCompare(right, undefined, { numeric: true, sensitivity: "base" });
    }

    function applySort() {
      if (state.sortIndex === null) return;
      Array.from(table.tBodies).forEach(function (body) {
        const rows = Array.from(body.rows), dataRows = rows.filter(isDataRow), otherRows = rows.filter(row => !isDataRow(row));
        const sorted = dataRows.slice().sort(function (left, right) { const result = compareValues(cellValue(left, state.sortIndex), cellValue(right, state.sortIndex)); return state.sortDirection === "ascending" ? result : -result; });
        if (sorted.some((row, rowIndex) => row !== dataRows[rowIndex])) [...sorted, ...otherRows].forEach(row => body.appendChild(row));
      });
      prepareRows();
    }

    function setupSorting() {
      const heading = serialHeader;
      const button = document.createElement("button"); button.type = "button"; button.className = "table-sort-button"; button.setAttribute("aria-label", "Sort by SR. No.; latest first by default");
      button.append(...Array.from(heading.childNodes));
      const indicator = document.createElement("span"); indicator.className = "table-sort-indicator"; indicator.setAttribute("aria-hidden", "true"); button.appendChild(indicator); heading.appendChild(button); heading.setAttribute("aria-sort", "descending");
      button.addEventListener("click", function () {
        state.sortDirection = state.sortDirection === "descending" ? "ascending" : "descending"; state.page = 1; heading.setAttribute("aria-sort", state.sortDirection); applySort(); update();
      });
    }

    function pageButton(label, page, disabled, current) {
      const control = document.createElement("button"); control.type = "button"; control.className = "table-page-button" + (current ? " is-current" : ""); control.textContent = label; control.disabled = disabled;
      if (current) control.setAttribute("aria-current", "page"); control.addEventListener("click", function () { state.page = page; update(); }); return control;
    }

    function update() {
      const rows = sourceRows(), available = rows.filter(row => !row.hidden && isDataRow(row)), totalPages = Math.max(1, Math.ceil(available.length / state.size));
      state.page = Math.min(Math.max(1, state.page), totalPages);
      const start = (state.page - 1) * state.size, end = Math.min(start + state.size, available.length);
      rows.forEach(row => row.classList.remove("is-pagination-hidden")); available.forEach((row, rowIndex) => row.classList.toggle("is-pagination-hidden", rowIndex < start || rowIndex >= end));
      summary.textContent = available.length ? "Showing " + (start + 1) + "–" + end + " of " + available.length + " rows" : "No rows to display"; navigation.replaceChildren();
      navigation.appendChild(pageButton("Previous", state.page - 1, state.page === 1, false));
      pageItems(totalPages, state.page).forEach(function (item) { if (typeof item === "string") { const ellipsis = document.createElement("span"); ellipsis.className = "table-page-ellipsis"; ellipsis.textContent = "…"; navigation.appendChild(ellipsis); } else navigation.appendChild(pageButton(String(item), item, false, item === state.page)); });
      navigation.appendChild(pageButton("Next", state.page + 1, state.page === totalPages, false));
    }

    sizeSelect.addEventListener("change", function () { state.size = Number(sizeSelect.value) || DEFAULT_SIZE; state.page = 1; update(); });
    const observer = new MutationObserver(function () { state.page = 1; prepareRows(); applySort(); update(); });
    Array.from(table.tBodies).forEach(body => observer.observe(body, { childList: true, subtree: true }));
    table.addEventListener("table-filtered", function () { state.page = 1; update(); });
    table.__portalPagination = { update: function (reset) { if (reset) state.page = 1; prepareRows(); applySort(); update(); } };
    prepareRows(); setupSorting(); applySort(); update();
  }

  document.querySelectorAll(".data-table").forEach(enhance);
  ["input", "change"].forEach(function (eventName) { document.addEventListener(eventName, function (event) { const table = event.target.closest("table"); if (table?.__portalPagination) window.setTimeout(function () { table.__portalPagination.update(true); }, 0); }); });
})();
