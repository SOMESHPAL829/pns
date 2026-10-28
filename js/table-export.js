(function () {
  const encoder = new TextEncoder();
  const crcTable = new Uint32Array(256);
  for (let n = 0; n < 256; n += 1) {
    let value = n;
    for (let bit = 0; bit < 8; bit += 1) value = (value & 1) ? (0xedb88320 ^ (value >>> 1)) : (value >>> 1);
    crcTable[n] = value >>> 0;
  }

  function crc32(bytes) {
    let crc = 0xffffffff;
    for (const byte of bytes) crc = crcTable[(crc ^ byte) & 0xff] ^ (crc >>> 8);
    return (crc ^ 0xffffffff) >>> 0;
  }

  function u16(value) { return new Uint8Array([value & 255, (value >>> 8) & 255]); }
  function u32(value) { return new Uint8Array([value & 255, (value >>> 8) & 255, (value >>> 16) & 255, (value >>> 24) & 255]); }
  function join(parts) {
    const size = parts.reduce((total, part) => total + part.length, 0);
    const output = new Uint8Array(size);
    let offset = 0;
    parts.forEach(function (part) { output.set(part, offset); offset += part.length; });
    return output;
  }

  function zip(files) {
    const localParts = [], centralParts = [];
    let offset = 0;
    const now = new Date();
    const dosTime = ((now.getHours() & 31) << 11) | ((now.getMinutes() & 63) << 5) | ((Math.floor(now.getSeconds() / 2)) & 31);
    const dosDate = (((now.getFullYear() - 1980) & 127) << 9) | (((now.getMonth() + 1) & 15) << 5) | (now.getDate() & 31);
    files.forEach(function (file) {
      const name = encoder.encode(file.name), data = encoder.encode(file.content), crc = crc32(data);
      const local = join([u32(0x04034b50), u16(20), u16(0x0800), u16(0), u16(dosTime), u16(dosDate), u32(crc), u32(data.length), u32(data.length), u16(name.length), u16(0), name, data]);
      localParts.push(local);
      centralParts.push(join([u32(0x02014b50), u16(20), u16(20), u16(0x0800), u16(0), u16(dosTime), u16(dosDate), u32(crc), u32(data.length), u32(data.length), u16(name.length), u16(0), u16(0), u16(0), u16(0), u32(0), u32(offset), name]));
      offset += local.length;
    });
    const central = join(centralParts);
    return join([...localParts, central, u32(0x06054b50), u16(0), u16(0), u16(files.length), u16(files.length), u32(central.length), u32(offset), u16(0)]);
  }

  function xml(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (char) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&apos;" }[char];
    }).replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g, "");
  }

  function columnName(index) {
    let name = "", value = index + 1;
    while (value) { value -= 1; name = String.fromCharCode(65 + (value % 26)) + name; value = Math.floor(value / 26); }
    return name;
  }

  function tableData(table) {
    const headerRow = Array.from(table.tHead?.rows || []).find(row => !row.classList.contains("col-filter-row"));
    if (!headerRow) throw new Error("This table has no exportable header.");
    const sourceHeaders = Array.from(headerRow.cells);
    const indexes = sourceHeaders.map((cell, index) => ({ cell, index })).filter(item => !/^(action|actions)$/i.test(item.cell.textContent.trim()) && item.cell.offsetParent !== null).map(item => item.index);
    const headers = indexes.map(index => sourceHeaders[index].innerText.replace(/\s+/g, " ").trim());
    const rows = Array.from(table.tBodies).flatMap(body => Array.from(body.rows)).filter(row => !row.hidden).map(function (row) {
      return indexes.map(function (index) {
        const cell = row.cells[index];
        if (!cell) return "";
        const checkbox = cell.querySelector('input[type="checkbox"]');
        if (checkbox) return checkbox.checked ? "Yes" : "No";
        const select = cell.querySelector("select");
        if (select) return select.selectedOptions[0]?.textContent.trim() || "";
        return cell.innerText.replace(/\s+/g, " ").trim();
      });
    });
    return { headers, rows };
  }

  function workbookFiles(title, data) {
    const allRows = [data.headers, ...data.rows];
    const sheetRows = allRows.map(function (row, rowIndex) {
      const cells = row.map(function (value, columnIndex) {
        const reference = columnName(columnIndex) + (rowIndex + 1);
        return '<c r="' + reference + '" t="inlineStr"' + (rowIndex === 0 ? ' s="1"' : "") + '><is><t xml:space="preserve">' + xml(value) + "</t></is></c>";
      }).join("");
      return '<row r="' + (rowIndex + 1) + '">' + cells + "</row>";
    }).join("");
    const widths = data.headers.map((header, index) => {
      const length = Math.min(45, Math.max(10, header.length + 3, ...data.rows.map(row => String(row[index] || "").length + 2)));
      return '<col min="' + (index + 1) + '" max="' + (index + 1) + '" width="' + length + '" customWidth="1"/>';
    }).join("");
    const dimension = "A1:" + columnName(Math.max(0, data.headers.length - 1)) + Math.max(1, allRows.length);
    return [
      { name: "[Content_Types].xml", content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>' },
      { name: "_rels/.rels", content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>' },
      { name: "docProps/core.xml", content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' + xml(title) + '</dc:title><dc:creator>KARAM OEE Portal</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' + new Date().toISOString() + "</dcterms:created></cp:coreProperties>" },
      { name: "docProps/app.xml", content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>KARAM OEE Portal</Application></Properties>' },
      { name: "xl/workbook.xml", content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' + xml(title.slice(0, 31) || "Data") + '" sheetId="1" r:id="rId1"/></sheets></workbook>' },
      { name: "xl/_rels/workbook.xml.rels", content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' },
      { name: "xl/styles.xml", content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F3A5F"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>' },
      { name: "xl/worksheets/sheet1.xml", content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="' + dimension + '"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>' + widths + '</cols><sheetData>' + sheetRows + '</sheetData><autoFilter ref="A1:' + columnName(Math.max(0, data.headers.length - 1)) + '1"/></worksheet>' }
    ];
  }

  function titleFor(table, index) {
    if (table.dataset.exportTitle) return table.dataset.exportTitle;
    const card = table.closest(".data-card, .dash-card, .form-card");
    const heading = card?.querySelector("h2,h3")?.textContent.trim();
    const page = table.closest("[data-view]")?.querySelector(".page-heading h1")?.textContent.trim();
    return heading || page || "Portal Data " + (index + 1);
  }

  function headingHost(table, title) {
    const scroll = table.closest(".data-scroll");
    const card = table.closest(".data-card, .dash-card, .form-card");
    const tables = card ? Array.from(card.querySelectorAll(".data-table")) : [];
    let heading = null;
    if (card && tables.length === 1) {
      heading = Array.from(card.children).find(child => child.classList.contains("dash-card-head")) || null;
    }
    if (!heading) {
      heading = document.createElement("div");
      heading.className = "dash-card-head table-section-heading";
      const label = document.createElement("h3");
      label.textContent = title;
      heading.appendChild(label);
      scroll.before(heading);
    }

    let actions = Array.from(heading.children).find(child => child.classList.contains("table-heading-actions"));
    if (!actions) {
      actions = document.createElement("div");
      actions.className = "table-heading-actions";
      Array.from(heading.children).filter(child => !/^H[1-6]$/.test(child.tagName) && child !== actions).forEach(child => actions.appendChild(child));
      heading.appendChild(actions);
    }
    return actions;
  }

  function exportTable(table, title) {
    const data = tableData(table);
    if (!data.headers.length) throw new Error("No exportable columns were found.");
    const bytes = zip(workbookFiles(title, data));
    const blob = new Blob([bytes], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = title.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") + "-" + new Date().toISOString().slice(0, 10) + ".xlsx";
    document.body.appendChild(link); link.click(); link.remove();
    window.setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
  }

  document.querySelectorAll(".data-table").forEach(function (table, index) {
    const scroll = table.closest(".data-scroll");
    if (!scroll || table.dataset.exportEnhanced === "true") return;
    table.dataset.exportEnhanced = "true";
    const title = titleFor(table, index);
    const button = document.createElement("button");
    button.type = "button";
    button.className = "btn ghost sm table-export-button";
    button.innerHTML = '<span data-icon="arrow-up-tray"></span><span>Export Excel</span>';
    button.setAttribute("aria-label", "Export " + title + " to Excel");
    button.addEventListener("click", function () {
      try { exportTable(table, title); window.AppToast?.show(title + " exported to Excel", "ok"); }
      catch (error) { window.AppToast?.show(error.message || "Excel export failed", "crit"); }
    });
    headingHost(table, title).appendChild(button);
  });
  window.ThemeIcons?.mount(document);
})();
