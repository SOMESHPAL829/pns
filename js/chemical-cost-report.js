(function () {
  const access = window.PortalAccess;
  const api = (...args) => window.PortalRequest(...args);
  const byId = (id) => document.getElementById(id);
  const state = {
    loading: false,
    loaded: false,
    rows: [],
    reportType: "weekly",
    machine: "ALL",
  };

  function localDate(date) {
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000)
      .toISOString()
      .slice(0, 10);
  }

  function displayDate(value) {
    if (!value) return "—";
    const parts = value.split("-").map(Number);
    return new Intl.DateTimeFormat("en-IN", {
      day: "2-digit",
      month: "short",
      year: "numeric",
    }).format(new Date(parts[0], parts[1] - 1, parts[2]));
  }

  function showAlert(message, isError) {
    const alert = byId("chemical-cost-report-alert");
    alert.textContent = message || "";
    alert.hidden = !message;
    alert.classList.toggle("error", !!isError);
  }

  function cell(row, value, className) {
    const td = document.createElement("td");
    if (className) td.className = className;
    td.textContent = value === "" ? "" : value == null ? "—" : String(value);
    row.appendChild(td);
    return td;
  }

  function decimal(value, digits) {
    return new Intl.NumberFormat("en-IN", {
      minimumFractionDigits: digits,
      maximumFractionDigits: digits,
    }).format(Number(value) || 0);
  }

  function money(value, digits) {
    if (value == null || Number.isNaN(Number(value))) return "—";
    return new Intl.NumberFormat("en-IN", {
      style: "currency",
      currency: "INR",
      minimumFractionDigits: digits,
      maximumFractionDigits: digits,
    }).format(Number(value));
  }

  function pct(value) {
    return (value > 0 ? "+" : "") + value.toFixed(1) + "%";
  }

  function fmtShort(value) {
    const abs = Math.abs(value);
    if (abs >= 1e5) return (value / 1e5).toFixed(1) + "L";
    if (abs >= 1e3) return (value / 1000).toFixed(0) + "k";
    return decimal(value, abs >= 10 ? 0 : 1);
  }

  function reportTypeLabel(value) {
    return (
      {
        weekly: "Week on Week",
        monthly: "Month on Month",
        consolidated: "Consolidated",
      }[value] || value
    );
  }

  function shortLabel(item, reportType) {
    if (
      reportType === "monthly" &&
      /^\d{4}-\d{2}$/.test(item.period_key || "")
    ) {
      const [year, month] = item.period_key.split("-").map(Number);
      return new Date(year, month - 1, 1).toLocaleDateString("en-GB", {
        month: "short",
        year: "2-digit",
      });
    }
    if (reportType === "weekly") {
      const match = String(item.report_period || "").match(/Week\s+(\d+)/i);
      if (match) return "W" + match[1];
      const key = String(item.period_key || "");
      const week = key.match(/W(\d+)/i);
      if (week) return "W" + week[1];
    }
    return item.machine_code || "Period";
  }

  function tipShow(event, text) {
    const tip = byId("chemical-cost-tip");
    tip.innerHTML = text;
    tip.hidden = false;
    tip.style.left =
      Math.min(event.clientX + 12, window.innerWidth - 230) + "px";
    tip.style.top = Math.max(8, event.clientY - 75) + "px";
  }

  function tipHide() {
    byId("chemical-cost-tip").hidden = true;
  }

  function chartRows(rows, reportType) {
    if (reportType === "consolidated") {
      return rows.map((item) => ({
        label: item.machine_code,
        period: item.report_period,
        machine: item.machine_code,
        area: Number(item.total_surface_area) || 0,
        cost:
          item.total_chemical_cost == null
            ? null
            : Number(item.total_chemical_cost),
        rate:
          item.chemical_cost_per_dm2 == null
            ? null
            : Number(item.chemical_cost_per_dm2),
        unpriced: Number(item.unpriced_entries || 0),
      }));
    }

    const groups = new Map();
    rows.forEach((item) => {
      const key = item.period_key || item.report_period;
      const existing = groups.get(key) || {
        label: shortLabel(item, reportType),
        period: item.report_period,
        machine: "All",
        area: 0,
        cost: 0,
        unpriced: 0,
        priced: true,
      };
      existing.area += Number(item.total_surface_area) || 0;
      existing.unpriced += Number(item.unpriced_entries || 0);
      if (item.total_chemical_cost == null) existing.priced = false;
      else existing.cost += Number(item.total_chemical_cost);
      groups.set(key, existing);
    });

    return Array.from(groups.values()).map((item) => {
      const cost = item.priced ? item.cost : null;
      const rate = cost == null || !(item.area > 0) ? null : cost / item.area;
      return { ...item, cost, rate };
    });
  }

  function renderMachines(machines, selected) {
    const select = byId("chemical-cost-report-machine");
    select.replaceChildren(new Option("All Machines", "ALL"));
    (machines || []).forEach((machine) =>
      select.add(new Option(machine, machine)),
    );
    select.value =
      selected === "ALL" || (machines || []).includes(selected)
        ? selected
        : "ALL";
  }

  function setTabs(reportType) {
    document.querySelectorAll("[data-cost-tab]").forEach((button) => {
      button.classList.toggle(
        "selected",
        button.dataset.costTab === reportType,
      );
    });
  }

  function renderKpis(series, reportType) {
    const priced = series.filter((row) => row.cost != null);
    const area = series.reduce((sum, row) => sum + row.area, 0);
    const cost = priced.reduce((sum, row) => sum + row.cost, 0);
    const rate =
      area > 0 && priced.length === series.length ? cost / area : null;
    const first = priced[0];
    const last = priced[priced.length - 1];
    const delta =
      first && last && priced.length > 1 && first.rate > 0
        ? (last.rate / first.rate - 1) * 100
        : null;
    const cards = [
      [
        "Total Chemical Cost",
        priced.length === series.length ? money(cost, 0) : "Incomplete",
        "Across " + series.length + " reporting periods",
      ],
      [
        "Total Surface Area",
        decimal(area, 0) + " dm²",
        "Processed surface area",
      ],
      [
        "Weighted Cost / dm²",
        rate == null ? "—" : "₹" + decimal(rate, 3),
        "Total cost ÷ total surface area",
      ],
      [
        "Unit Cost Change",
        delta == null ? "—" : pct(delta),
        reportType === "weekly"
          ? "First-to-last week comparison"
          : reportType === "monthly"
            ? "First-to-last month comparison"
            : "First-to-last period comparison",
      ],
    ];
    byId("chemical-cost-report-kpis").innerHTML = cards
      .map((card, index) => {
        const deltaClass =
          index === 3 && delta != null ? (delta <= 0 ? " ok" : " warn") : "";
        const foot =
          index === 3 && delta != null
            ? card[2] + " · " + (delta <= 0 ? "↓ improvement" : "↑ increase")
            : card[2];
        return (
          '<div class="dash-kpi chemical-cost-kpi">' +
          '<div class="l">' +
          card[0] +
          "</div>" +
          '<div class="v' +
          deltaClass +
          '">' +
          card[1] +
          "</div>" +
          '<div class="chemical-cost-kpi-foot">' +
          foot +
          "</div>" +
          "</div>"
        );
      })
      .join("");
  }

  function svgChart(el, values, opts) {
    const root = byId(el);
    const usable = values.filter((row) =>
      opts.keys.every((key) => row[key] != null && !Number.isNaN(row[key])),
    );
    if (!usable.length) {
      root.innerHTML =
        '<div class="chemical-cost-empty">No chartable data in selected range</div>';
      return;
    }

    const W = 750;
    const H = 286;
    const left = opts.percent ? 47 : 65;
    const right = 12;
    const top = 19;
    const bottom = 42;
    const plotW = W - left - right;
    const plotH = H - top - bottom;
    const max =
      Math.max(
        ...usable.flatMap((row) =>
          opts.keys.map((key) => Number(row[key]) || 0),
        ),
        0,
      ) * 1.17 || 1;
    const y = (value) => top + plotH - (value / max) * plotH;
    const x = (index) =>
      left +
      (usable.length === 1 ? plotW / 2 : (index * plotW) / (usable.length - 1));
    const xbar = (index) => left + ((index + 0.5) * plotW) / usable.length;
    const axisLabel = (fraction) => {
      const value = max * fraction;
      if (opts.percent) return Math.round(value);
      if (opts.currency) return "₹" + fmtShort(value);
      if (opts.rate) return decimal(value, 1);
      return fmtShort(value);
    };

    let out =
      '<svg viewBox="0 0 ' +
      W +
      " " +
      H +
      '" preserveAspectRatio="none" role="img">';
    for (let step = 0; step < 5; step += 1) {
      const gy = top + (step * plotH) / 4;
      out +=
        '<line x1="' +
        left +
        '" y1="' +
        gy +
        '" x2="' +
        (W - right) +
        '" y2="' +
        gy +
        '" stroke="currentColor" class="chemical-cost-gridline" stroke-dasharray="3 4"/>';
      out +=
        '<text x="' +
        (left - 9) +
        '" y="' +
        (gy + 4) +
        '" text-anchor="end" class="chemical-cost-axis">' +
        axisLabel((4 - step) / 4) +
        "</text>";
    }
    usable.forEach((row, index) => {
      const xx = opts.kind === "bar" ? xbar(index) : x(index);
      out +=
        '<text x="' +
        xx +
        '" y="' +
        (H - 11) +
        '" text-anchor="middle" class="chemical-cost-axis">' +
        row.label +
        "</text>";
    });

    if (opts.kind === "bar") {
      const color = (opts.colors && opts.colors[0]) || "#3976b4";
      usable.forEach((row, index) => {
        const xx = xbar(index);
        const width = Math.min(48, (plotW / usable.length) * 0.57);
        const yy = y(row[opts.keys[0]]);
        out +=
          '<rect class="chemical-cost-mark" data-i="' +
          index +
          '" x="' +
          (xx - width / 2) +
          '" y="' +
          yy +
          '" width="' +
          width +
          '" height="' +
          (top + plotH - yy) +
          '" rx="4" fill="' +
          color +
          '" opacity=".9"/>';
      });
    } else {
      opts.keys.forEach((key, keyIndex) => {
        const color =
          (opts.colors && opts.colors[keyIndex]) ||
          ["#3976b4", "#17a18f"][keyIndex];
        const path = usable
          .map(
            (row, index) =>
              (index ? "L" : "M") +
              x(index).toFixed(1) +
              " " +
              y(row[key]).toFixed(1),
          )
          .join(" ");
        if (opts.fill && keyIndex === 0) {
          out +=
            '<path d="' +
            path +
            " L " +
            x(usable.length - 1) +
            " " +
            (top + plotH) +
            " L " +
            x(0) +
            " " +
            (top + plotH) +
            ' Z" fill="#3976b4" opacity=".08"/>';
        }
        out +=
          '<path d="' +
          path +
          '" stroke="' +
          color +
          '" fill="none" stroke-width="3" stroke-linejoin="round"/>';
        usable.forEach((row, index) => {
          out +=
            '<circle class="chemical-cost-mark" data-i="' +
            index +
            '" cx="' +
            x(index) +
            '" cy="' +
            y(row[key]) +
            '" r="5" fill="white" stroke="' +
            color +
            '" stroke-width="2.7"/>';
        });
      });
    }
    out += "</svg>";
    root.innerHTML = out;
    root.querySelectorAll(".chemical-cost-mark").forEach((mark) => {
      mark.addEventListener("pointermove", (event) => {
        const row = usable[Number(mark.dataset.i)];
        tipShow(
          event,
          "<b>" +
            row.period +
            "</b><br>Cost: " +
            money(row.cost, 0) +
            "<br>Area: " +
            decimal(row.area, 2) +
            " dm²<br>Cost / dm²: " +
            (row.rate == null ? "—" : "₹" + decimal(row.rate, 4)),
        );
      });
      mark.addEventListener("pointerleave", tipHide);
    });
  }

  function renderCharts(series, reportType) {
    const typeLabel =
      reportType === "weekly"
        ? "weekly"
        : reportType === "monthly"
          ? "monthly"
          : "consolidated";
    byId("chemical-cost-trend-caption").textContent =
      "Chemical unit-cost evolution · " + typeLabel;
    byId("chemical-cost-table-caption").textContent =
      "Period-wise financial and surface-area performance · " + typeLabel;
    svgChart("chemical-cost-trend", series, {
      keys: ["rate"],
      rate: true,
      fill: true,
    });
    svgChart("chemical-cost-cost-chart", series, {
      keys: ["cost"],
      kind: "bar",
      currency: true,
    });
    svgChart("chemical-cost-area-chart", series, {
      keys: ["area"],
      kind: "bar",
      colors: ["#1aa193"],
    });
    const baseCost = series.find((row) => row.cost != null)?.cost || 1;
    const baseArea = series.find((row) => row.area > 0)?.area || 1;
    svgChart(
      "chemical-cost-indexed-chart",
      series.map((row) => ({
        ...row,
        costIndex: row.cost == null ? null : (row.cost / baseCost) * 100,
        areaIndex: (row.area / baseArea) * 100,
      })),
      { keys: ["costIndex", "areaIndex"], percent: true },
    );
  }

  function renderTable(rows) {
    const body = byId("chemical-cost-report-body");
    body.replaceChildren();
    let missingRateEntries = 0;
    const lastRateByMachine = new Map();

    rows.forEach((item, index) => {
      const row = document.createElement("tr");
      const missingRates = Number(item.unpriced_entries || 0);
      missingRateEntries += missingRates;
      const rate =
        item.chemical_cost_per_dm2 == null
          ? null
          : Number(item.chemical_cost_per_dm2);
      const previous = lastRateByMachine.get(item.machine_code);
      const change =
        previous != null && rate != null && previous > 0
          ? (rate / previous - 1) * 100
          : null;
      if (rate != null) lastRateByMachine.set(item.machine_code, rate);

      cell(row, index + 1, "chemical-report-serial");
      cell(row, item.machine_code, "mono");
      cell(row, item.report_period);
      cell(row, decimal(item.total_surface_area, 2), "r");
      cell(
        row,
        missingRates
          ? "Rate Missing (" + missingRates + ")"
          : money(item.total_chemical_cost, 2),
        missingRates ? "chemical-cost-missing r" : "r",
      );
      cell(
        row,
        missingRates
          ? "Rate Missing"
          : rate == null
            ? "Not Available"
            : "₹" + decimal(rate, 4),
        missingRates ? "chemical-cost-missing r" : "r",
      );
      const changeCell = cell(row, change == null ? "—" : pct(change), "r");
      if (change != null)
        changeCell.style.color = change > 0 ? "var(--crit)" : "var(--ok)";
      const performance = document.createElement("td");
      const pill = document.createElement("span");
      pill.className =
        "chemical-cost-pill" + (change == null || change <= 0 ? " low" : "");
      pill.textContent =
        change == null ? "Baseline" : change > 0 ? "Higher cost" : "Lower cost";
      performance.appendChild(pill);
      row.appendChild(performance);
      body.appendChild(row);
    });

    if (!rows.length) {
      const row = document.createElement("tr");
      const empty = cell(
        row,
        "No production or chemical consumption data matches the selected filters.",
      );
      empty.colSpan = 8;
      body.appendChild(row);
    }

    byId("chemical-cost-report-row-count").textContent =
      "Showing " + rows.length + " " + (rows.length === 1 ? "row" : "rows");
    return missingRateEntries;
  }

  function render(data) {
    state.rows = data.rows || [];
    state.reportType = data.report_type;
    state.machine = data.machine;
    renderMachines(data.machines, data.machine);
    byId("chemical-cost-report-from-display").textContent = displayDate(
      data.from_date,
    );
    byId("chemical-cost-report-to-display").textContent = displayDate(
      data.to_date,
    );
    byId("chemical-cost-report-machine-display").textContent =
      data.machine === "ALL" ? "All Machines" : data.machine;
    byId("chemical-cost-report-type-display").textContent = reportTypeLabel(
      data.report_type,
    );
    byId("chemical-cost-report-type").value = data.report_type;
    byId("chemical-cost-report-scope").textContent =
      "● " +
      (data.machine === "ALL" ? "All machines" : data.machine) +
      " · Chemical cost monitoring";
    setTabs(data.report_type === "monthly" ? "monthly" : "weekly");

    const series = chartRows(state.rows, data.report_type);
    renderKpis(series, data.report_type);
    renderCharts(series, data.report_type);
    const missingRateEntries = renderTable(state.rows);

    state.loaded = true;
    byId("chemical-cost-report-print").disabled = false;
    byId("chemical-cost-report-export").disabled = !state.rows.length;
    showAlert(
      missingRateEntries
        ? missingRateEntries +
            " consumption " +
            (missingRateEntries === 1 ? "entry has" : "entries have") +
            " no effective INR rate. Affected costs are withheld until rates are registered."
        : "",
      false,
    );
  }

  function exportCsv() {
    if (!state.rows.length) return;
    const heads = [
      "Machine",
      "Report Period",
      "Total Surface Area (dm2)",
      "Total Chemical Cost (INR)",
      "Chemical Cost per dm2 (INR)",
      "Unpriced Entries",
    ];
    const lines = [heads].concat(
      state.rows.map((row) => [
        row.machine_code,
        row.report_period,
        Number(row.total_surface_area || 0).toFixed(4),
        row.total_chemical_cost == null
          ? ""
          : Number(row.total_chemical_cost).toFixed(4),
        row.chemical_cost_per_dm2 == null
          ? ""
          : Number(row.chemical_cost_per_dm2).toFixed(6),
        Number(row.unpriced_entries || 0),
      ]),
    );
    const csv = lines
      .map((line) =>
        line
          .map((value) => '"' + String(value).replace(/"/g, '""') + '"')
          .join(","),
      )
      .join("\r\n");
    const link = document.createElement("a");
    link.href = URL.createObjectURL(
      new Blob([csv], { type: "text/csv;charset=utf-8" }),
    );
    link.download = "KARAM-Chemical-Cost-" + state.reportType + ".csv";
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
  }

  async function load() {
    if (!access.can("chemical-cost-report") || state.loading) return;
    state.loading = true;
    const run = byId("chemical-cost-report-run");
    run.disabled = true;
    try {
      const data = await api("chemical-consumption", "cost-report", {
        query: {
          from_date: byId("chemical-cost-report-from").value,
          to_date: byId("chemical-cost-report-to").value,
          machine: byId("chemical-cost-report-machine").value || "ALL",
          report_type: byId("chemical-cost-report-type").value,
        },
      });
      if (!data.available)
        throw new Error(
          data.message || "Chemical cost reporting is unavailable.",
        );
      render(data);
    } catch (error) {
      showAlert(error.message, true);
    } finally {
      state.loading = false;
      run.disabled = false;
    }
  }

  byId("chemical-cost-report-filter-form").addEventListener(
    "submit",
    function (event) {
      event.preventDefault();
      load();
    },
  );

  byId("chemical-cost-report-print").addEventListener("click", function () {
    if (state.loaded) window.print();
  });

  byId("chemical-cost-report-export").addEventListener("click", exportCsv);

  document.querySelectorAll("[data-cost-tab]").forEach((button) => {
    button.addEventListener("click", function () {
      byId("chemical-cost-report-type").value = button.dataset.costTab;
      setTabs(button.dataset.costTab);
      load();
    });
  });

  const now = new Date();
  byId("chemical-cost-report-from").value = localDate(
    new Date(now.getFullYear(), now.getMonth(), 1),
  );
  byId("chemical-cost-report-to").value = localDate(now);
  byId("chemical-cost-report-print").disabled = true;
  byId("chemical-cost-report-export").disabled = true;
  window.addEventListener("portal-view-changed", (event) => {
    if (event.detail.route === "chemical-cost-report") load();
  });
})();
