(function () {
  "use strict";

  function initCoa(root) {
    var form = root.querySelector(".olr-coa-search__form");
    var input = root.querySelector("#olr-coa-search-input");
    var tabs = Array.prototype.slice.call(root.querySelectorAll("[data-coa-filter]"));
    var rows = Array.prototype.slice.call(root.querySelectorAll("[data-coa-row]"));
    var empty = root.querySelector(".olr-coa-empty");
    var activeCategory = "all";

    if (!form || !input || !rows.length) {
      return;
    }

    function update() {
      var query = input.value.trim().toLowerCase();
      var visible = 0;

      rows.forEach(function (row) {
        var category = row.getAttribute("data-coa-category") || "other";
        var searchText = (row.getAttribute("data-coa-search") || row.textContent).toLowerCase();
        var categoryMatch = activeCategory === "all" || category === activeCategory;
        var searchMatch = !query || searchText.indexOf(query) !== -1;
        var show = categoryMatch && searchMatch;

        row.hidden = !show;
        if (show) {
          visible += 1;
        }
      });

      if (empty) {
        empty.hidden = visible !== 0;
      }
    }

    function selectCategory(category) {
      activeCategory = category;
      tabs.forEach(function (tab) {
        var active = tab.getAttribute("data-coa-filter") === category;
        tab.classList.toggle("is-active", active);
        tab.setAttribute("aria-pressed", active ? "true" : "false");
      });
      update();
    }

    tabs.forEach(function (tab) {
      tab.addEventListener("click", function () {
        selectCategory(tab.getAttribute("data-coa-filter") || "all");
      });
    });

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      update();
    });

    input.addEventListener("input", update);
    input.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        input.value = "";
        selectCategory("all");
      }
    });
  }

  document.querySelectorAll("[data-olr-coa]").forEach(initCoa);
})();
