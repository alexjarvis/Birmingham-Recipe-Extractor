document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll("th").forEach((header, index) => {
        header.addEventListener("click", () => sortTable(index, header));
    });
});

function sortTable(columnIndex, header) {
    const table = document.querySelector("table tbody");
    const rows = Array.from(table.rows);
    const isAscending = header.classList.toggle("sort-desc", !header.classList.contains("sort-desc"));

    document.querySelectorAll("th").forEach(th => {
        if (th !== header) th.classList.remove("sort-asc", "sort-desc");
    });

    rows.sort((a, b) => {
        const aText = a.cells[columnIndex].textContent.trim();
        const bText = b.cells[columnIndex].textContent.trim();

        return isAscending
            ? bText.localeCompare(aText, undefined, {numeric: true, sensitivity: 'base'})
            : aText.localeCompare(bText, undefined, {numeric: true, sensitivity: 'base'});
    });

    table.innerHTML = "";
    rows.forEach(row => table.appendChild(row));
}