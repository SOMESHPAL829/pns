(function () {
  const DEFAULT_SIZE = 15;
  const FILTERABLE = new Set([
    "machine", "machinecode", "line", "linemachine", "shift", "type", "losstype",
    "status", "state", "role", "source", "uom", "usedfor", "grade", "colour",
    "gradecolour", "periodstatus", "performance", "chemicalname", "chemicalnamecode",
  ]);
  const LATEST_PRIORITY = [
    "received", "updated", "created", "date", "reportdate", "entrydate", "start",
    "effectivefrom", "reportperiod", "id", "number",
  ];
  const normalizedHeading = value => String(value || "").replace(/[^a-z0-9]/gi, "").toLowerCase();
  const normalizedValue = value => String(value || "").replace(/\s+/g, " ").trim();
  const isFilterableHeading = heading => FILTERABLE.has(heading) || heading.startsWith("usedfor") || heading.startsWith("recommendedby");

  function pageItems(total, current) {
    if (total <= 7) return Array.from({ length: total }, (_, index) => index + 1);
    const values = new Set([1, total, current - 1, current, current + 1]);
    if (current <= 3) [2, 3, 4].forEach(value => values.add(value));
    if (current >= total - 2) [total - 3, total - 2, total - 1].forEach(value => values.add(value));
    const pages = Array.from(values).filter(value => value > 0 && value <= total).sort((a, b) => a - b);
    const output = [];
    pages.forEach(function (page, index) {
      if (index && page - pages[index - 1] > 1) output.push("ellipsis-" + page);
      output.push(page);
    });
    return output;
  }

  function enhance(table, index) {
    const scroll = table.closest(".data-scroll");
    if (!scroll || scroll.nextElementSibling?.classList.contains("table-pagination")) return;
    const headerRow = Array.from(table.tHead?.rows || []).find(row => !row.classList.contains("col-filter-row"));
    if (!headerRow) return;

    const initialHeadings = Array.from(headerRow.cells).map(cell => normalizedHeading(cell.textContent));
    const serialIndex = initialHeadings.findIndex(value => value === "srno" || value === "sr" || value === "serialno");
    if (serialIndex >= 0) {
      Array.from(table.tHead.rows).forEach(row => row.cells[serialIndex]?.remove());
      Array.from(table.tBodies).forEach(body => Array.from(body.rows).forEach(function (row) {
        if (row.cells.length === 1 && Number(row.cells[0].colSpan) > 1) row.cells[0].colSpan = Math.max(1, row.cells[0].colSpan - 1);
        else row.cells[serialIndex]?.remove();
      }));
    }

    const headings = Array.from(headerRow.cells).map(cell => normalizedHeading(cell.textContent));
    const state = { page: 1, size: DEFAULT_SIZE, sortIndex: null, sortDirection: "descending", filters: new Map(), search: "" };
    const pagination = document.createElement("div");
    pagination.className = "table-pagination";
    pagination.setAttribute("aria-label", "Table pagination");
    const summary = document.createElement("div");
    summary.className = "table-pagination-summary";
    const controls = document.createElement("div");
    controls.className = "table-pagination-controls";
    const sizeLabel = document.createElement("label");
    sizeLabel.className = "table-page-size";
    sizeLabel.append(document.createTextNode("Rows per page"));
    const sizeSelect = document.createElement("select");
    sizeSelect.setAttribute("aria-label", "Rows per page");
    [15, 25, 50, 100].forEach(value => sizeSelect.add(new Option(String(value), String(value), value === DEFAULT_SIZE, value === DEFAULT_SIZE)));
    sizeLabel.appendChild(sizeSelect);
    const navigation = document.createElement("nav");
    navigation.className = "table-page-navigation";
    navigation.setAttribute("aria-label", "Pagination for table " + (index + 1));
    controls.append(sizeLabel, navigation);
    pagination.append(summary, controls);
    scroll.after(pagination);

    const sourceRows = () => Array.from(table.tBodies).flatMap(body => Array.from(body.rows));
    const isDataRow = row => row.cells.length === headings.length && !Array.from(row.cells).some(cell => Number(cell.colSpan) > 1);

    function cleanActionCells(row) {
      headings.forEach(function (heading, cellIndex) {
        if (!/^actions?$/.test(heading)) return;
        const cell = row.cells[cellIndex];
        if (!cell) return;
        Array.from(cell.childNodes).forEach(function (node) {
          if (node.nodeType === Node.TEXT_NODE && /^[\s\u2013\u2014-]*$/.test(node.textContent)) node.remove();
        });
      });
    }

    function prepareRows() {
      const dataRows = sourceRows().filter(isDataRow);
      dataRows.forEach(function (row, rowIndex) {
        if (!row.dataset.tableOrder) row.dataset.tableOrder = String(dataRows.length - rowIndex);
        cleanActionCells(row);
      });
      return dataRows;
    }

    function cellValue(row, cellIndex) {
      const cell = row.cells[cellIndex];
      if (!cell) return "";
      const checkbox = cell.querySelector('input[type="checkbox"]');
      if (checkbox) return checkbox.checked ? "1" : "0";
      const select = cell.querySelector("select");
      if (select) return normalizedValue(select.options[select.selectedIndex]?.text || select.value);
      return normalizedValue(cell.dataset.sortValue || cell.innerText || "");
    }

    function compareValues(left, right) {
      const numeric = /^[₹$€£]?\s*-?[\d,]+(?:\.\d+)?\s*%?$/;
      if (numeric.test(left) && numeric.test(right)) return Number(left.replace(/[^\d.-]/g, "")) - Number(right.replace(/[^\d.-]/g, ""));
      const leftDate = Date.parse(left);
      const rightDate = Date.parse(right);
      if (/\d/.test(left) && /\d/.test(right) && Number.isFinite(leftDate) && Number.isFinite(rightDate)) return leftDate - rightDate;
      return left.localeCompare(right, undefined, { numeric: true, sensitivity: "base" });
    }

    function applySort() {
      if (state.sortIndex === null) return;
      Array.from(table.tBodies).forEach(function (body) {
        const rows = Array.from(body.rows);
        const dataRows = rows.filter(isDataRow);
        const otherRows = rows.filter(row => !isDataRow(row));
        const sorted = dataRows.slice().sort(function (left, right) {
          const result = compareValues(cellValue(left, state.sortIndex), cellValue(right, state.sortIndex));
          if (result === 0) return Number(right.dataset.tableOrder || 0) - Number(left.dataset.tableOrder || 0);
          return state.sortDirection === "ascending" ? result : -result;
        });
        if (sorted.some((row, rowIndex) => row !== dataRows[rowIndex])) [...sorted, ...otherRows].forEach(row => body.appendChild(row));
      });
    }

    function setSort(indexToSort) {
      if (state.sortIndex === indexToSort) state.sortDirection = state.sortDirection === "descending" ? "ascending" : "descending";
      else {
        state.sortIndex = indexToSort;
        state.sortDirection = "ascending";
      }
      Array.from(headerRow.cells).forEach(function (heading, headingIndex) {
        heading.removeAttribute("aria-sort");
        if (headingIndex === state.sortIndex) heading.setAttribute("aria-sort", state.sortDirection);
      });
      state.page = 1;
      applySort();
      update();
    }

    function setupSorting() {
      Array.from(headerRow.cells).forEach(function (heading, headingIndex) {
        if (/^actions?$/.test(headings[headingIndex]) || isFilterableHeading(headings[headingIndex])) return;
        const label = normalizedValue(heading.textContent);
        const button = document.createElement("button");
        button.type = "button";
        button.className = "table-sort-button";
        button.setAttribute("aria-label", "Sort by " + label);
        button.append(...Array.from(heading.childNodes));
        const indicator = document.createElement("span");
        indicator.className = "table-sort-indicator";
        indicator.setAttribute("aria-hidden", "true");
        button.appendChild(indicator);
        heading.appendChild(button);
        button.addEventListener("click", function () { setSort(headingIndex); });
      });
      const defaultHeading = LATEST_PRIORITY.find(name => headings.includes(name));
      if (defaultHeading) {
        state.sortIndex = headings.indexOf(defaultHeading);
        state.sortDirection = "descending";
        headerRow.cells[state.sortIndex]?.setAttribute("aria-sort", "descending");
      }
    }

    headings.forEach(function (heading, headingIndex) {
      if (isFilterableHeading(heading)) {
        const cell = headerRow.cells[headingIndex];
        const label = normalizedValue(cell.textContent);
        const select = document.createElement("select");
        select.className = "table-heading-filter";
        select.setAttribute("aria-label", "Filter " + label);
        select.dataset.columnIndex = String(headingIndex);
        select.dataset.headingLabel = label;
        select.add(new Option(label, ""));
        select.addEventListener("change", function () {
          if (select.value) state.filters.set(headingIndex, select.value);
          else state.filters.delete(headingIndex);
          state.page = 1;
          applyFilters();
          update();
        });
        cell.classList.add("is-filter-heading");
        cell.replaceChildren(select);
      }
    });

    function refreshFilterOptions() {
      const rows = prepareRows();
      headerRow.querySelectorAll(".table-heading-filter").forEach(function (select) {
        const cellIndex = Number(select.dataset.columnIndex);
        const selected = select.value;
        const label = select.dataset.headingLabel || "Filter";
        const values = Array.from(new Set(rows.map(row => cellValue(row, cellIndex)).filter(Boolean))).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: "base" }));
        select.replaceChildren(new Option(label, ""));
        values.slice(0, 100).forEach(value => select.add(new Option(label + ": " + value, value)));
        if (values.includes(selected)) select.value = selected;
        else if (selected) state.filters.delete(cellIndex);
      });
    }

    function applyFilters() {
      prepareRows().forEach(function (row) {
        const matchesColumns = Array.from(state.filters).every(([cellIndex, expected]) => cellValue(row, cellIndex) === expected);
        const matchesSearch = !state.search || normalizedValue(row.innerText).toLowerCase().includes(state.search);
        const visible = matchesColumns && matchesSearch;
        row.classList.toggle("is-column-filtered", !visible);
      });
    }

    function pageButton(label, page, disabled, current) {
      const control = document.createElement("button");
      control.type = "button";
      control.className = "table-page-button" + (current ? " is-current" : "");
      control.textContent = label;
      control.disabled = disabled;
      if (current) control.setAttribute("aria-current", "page");
      control.addEventListener("click", function () { state.page = page; update(); });
      return control;
    }

    function update() {
      const rows = sourceRows();
      const available = rows.filter(row => !row.hidden && !row.classList.contains("is-column-filtered") && isDataRow(row));
      const totalPages = Math.max(1, Math.ceil(available.length / state.size));
      state.page = Math.min(Math.max(1, state.page), totalPages);
      const start = (state.page - 1) * state.size;
      const end = Math.min(start + state.size, available.length);
      rows.forEach(row => row.classList.remove("is-pagination-hidden"));
      available.forEach((row, rowIndex) => row.classList.toggle("is-pagination-hidden", rowIndex < start || rowIndex >= end));
      summary.textContent = available.length ? "Showing " + (start + 1) + "–" + end + " of " + available.length + " rows" : "No rows to display";
      navigation.replaceChildren();
      navigation.appendChild(pageButton("Previous", state.page - 1, state.page === 1, false));
      pageItems(totalPages, state.page).forEach(function (item) {
        if (typeof item === "string") {
          const ellipsis = document.createElement("span");
          ellipsis.className = "table-page-ellipsis";
          ellipsis.textContent = "…";
          navigation.appendChild(ellipsis);
        } else navigation.appendChild(pageButton(String(item), item, false, item === state.page));
      });
      navigation.appendChild(pageButton("Next", state.page + 1, state.page === totalPages, false));
    }

    sizeSelect.addEventListener("change", function () {
      state.size = Number(sizeSelect.value) || DEFAULT_SIZE;
      state.page = 1;
      update();
    });
    let queued = false;
    const observer = new MutationObserver(function () {
      if (queued) return;
      queued = true;
      window.setTimeout(function () {
        queued = false;
        state.page = 1;
        refreshFilterOptions();
        applyFilters();
        applySort();
        update();
      }, 0);
    });
    Array.from(table.tBodies).forEach(body => observer.observe(body, { childList: true, subtree: true }));
    table.__portalPagination = {
      update: function (reset) {
        if (reset) state.page = 1;
        refreshFilterOptions();
        applyFilters();
        applySort();
        update();
      },
      search: function (value) {
        state.search = normalizedValue(value).toLowerCase();
        state.page = 1;
        applyFilters();
        update();
      },
    };
    prepareRows();
    setupSorting();
    refreshFilterOptions();
    applyFilters();
    applySort();
    update();
  }

  document.querySelectorAll(".data-table").forEach(enhance);
  document.querySelectorAll("[data-table-search]").forEach(function (input) {
    const target = document.querySelector(input.dataset.tableSearch || "");
    const table = target?.closest("table") || (target?.matches("table") ? target : null);
    if (!table?.__portalPagination) return;
    input.addEventListener("input", function () {
      table.__portalPagination.search(input.value);
    });
  });
  ["input", "change"].forEach(function (eventName) {
    document.addEventListener(eventName, function (event) {
      const table = event.target.closest("table");
      if (table?.__portalPagination && !event.target.classList.contains("table-heading-filter")) {
        window.setTimeout(function () { table.__portalPagination.update(true); }, 0);
      }
    });
  });
})();
