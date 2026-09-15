(function () {
  "use strict";

  function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(value);
    }

    return new Promise(function (resolve, reject) {
      var field = document.createElement("textarea");
      field.value = value;
      field.setAttribute("readonly", "");
      field.style.position = "fixed";
      field.style.opacity = "0";
      document.body.appendChild(field);
      field.select();
      try {
        document.execCommand("copy");
        resolve();
      } catch (error) {
        reject(error);
      } finally {
        field.remove();
      }
    });
  }

  function initializeCopyButtons(hub, status) {
    hub.querySelectorAll("[data-olr-copy]").forEach(function (button) {
      button.addEventListener("click", function () {
        var value = button.getAttribute("data-copy-value") || "";
        var original = button.innerHTML;
        if (!value) {
          return;
        }

        copyText(value).then(function () {
          var label = window.olrAccountHub && olrAccountHub.copied ? olrAccountHub.copied : "Copied";
          button.textContent = label;
          status.textContent = label;
          window.setTimeout(function () {
            button.innerHTML = original;
          }, 1600);
        });
      });
    });
  }

  function initializeNavigation(hub) {
    var account = hub.querySelector(".um-account");
    var side = hub.querySelector(".um-account-side");
    var navigationShell = side ? side.parentNode : null;
    if (!account || !side || !navigationShell) {
      return;
    }

    // Full-page account navigation should not depend on UM's tab animation script.
    var selected = side.querySelector("a.um-account-link.current[data-tab]");
    if (selected) {
      hub.querySelectorAll(".um-account-tab[data-tab]").forEach(function (panel) {
        panel.style.display = panel.getAttribute("data-tab") === selected.getAttribute("data-tab") ? "block" : "none";
      });
    }

    var brand = hub.querySelector(":scope > .olr-account-brand");
    if (brand) {
      side.insertBefore(brand, side.firstChild);
    }

    var toggle = document.createElement("button");
    var label = window.olrAccountHub && olrAccountHub.menuLabel ? olrAccountHub.menuLabel : "Account menu";
    var sideId = side.id || "olr-account-navigation";
    side.id = sideId;
    toggle.type = "button";
    toggle.className = "olr-account-menu-toggle";
    toggle.setAttribute("aria-controls", sideId);
    toggle.setAttribute("aria-expanded", "false");
    toggle.innerHTML = "<span>" + label + "</span><span aria-hidden=\"true\">+</span>";
    // Keep the mobile control out of the desktop grid and keyboard order,
    // even when the theme overrides the default display style for buttons.
    var mobileNavigation = window.matchMedia("(max-width: 50rem)");
    function syncNavigationViewport() {
      toggle.hidden = !mobileNavigation.matches;
      account.classList.remove("olr-account-nav-open");
      toggle.setAttribute("aria-expanded", "false");
      toggle.lastElementChild.textContent = "+";
    }
    syncNavigationViewport();
    mobileNavigation.addEventListener("change", syncNavigationViewport);
    account.classList.add("olr-account-has-toggle");
    navigationShell.insertBefore(toggle, side);

    toggle.addEventListener("click", function () {
      var open = account.classList.toggle("olr-account-nav-open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      toggle.lastElementChild.textContent = open ? "\u2212" : "+";
      if (open) {
        var firstLink = side.querySelector("a[href]");
        if (firstLink) {
          firstLink.focus({ preventScroll: true });
        }
      }
    });

    account.addEventListener("keydown", function (event) {
      if (event.key !== "Escape" || !account.classList.contains("olr-account-nav-open")) {
        return;
      }
      event.preventDefault();
      account.classList.remove("olr-account-nav-open");
      toggle.setAttribute("aria-expanded", "false");
      toggle.lastElementChild.textContent = "+";
      toggle.focus({ preventScroll: true });
    });

    var logoutUrl = window.olrAccountHub && olrAccountHub.logoutUrl ? olrAccountHub.logoutUrl : "";
    var guidelinesUrl = window.olrAccountHub && olrAccountHub.guidelinesUrl ? olrAccountHub.guidelinesUrl : "";
    side.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function (event) {
        account.classList.remove("olr-account-nav-open");
        toggle.setAttribute("aria-expanded", "false");
        toggle.lastElementChild.textContent = "+";

        if (logoutUrl && link.matches('a.um-account-link[data-tab="olr_logout"], a[href*="um_tab=olr_logout"], a[href*="/account/olr_logout/"]')) {
          event.preventDefault();
          event.stopImmediatePropagation();
          window.location.assign(logoutUrl);
          return;
        }

        if (guidelinesUrl && link.matches('a.um-account-link[data-tab="guidelines"], a[href*="um_tab=guidelines"], a[href*="/account/guidelines/"]')) {
          event.preventDefault();
          event.stopImmediatePropagation();
          window.location.assign(guidelinesUrl);
          return;
        }

        /*
         * UM normally reveals an already-rendered tab in place. Affiliate tabs
         * can contain independent native forms, so the hub renders only the
         * requested section. A normal navigation keeps the HTML valid and loads
         * the correct fresh UAP data for every destination.
         */
        if (link.matches("a.um-account-link[data-tab]") && link.getAttribute("data-tab") !== "olr_logout") {
          event.preventDefault();
          event.stopImmediatePropagation();
          window.location.assign(link.href);
        }
      }, true);
    });
  }

  function initializeTables(hub) {
    // Give native report/history tables the same readable mobile rows as orders.
    hub.querySelectorAll("table").forEach(function (table) {
      var headings = Array.from(table.querySelectorAll("thead tr:first-child th"));
      if (!headings.length || table.querySelector("tbody [rowspan], tbody td[colspan]:not([colspan='1'])")) {
        return;
      }
      table.querySelectorAll("tbody tr").forEach(function (row) {
        Array.from(row.cells).forEach(function (cell, index) {
          if (headings[index] && !cell.hasAttribute("data-label")) {
            cell.setAttribute("data-label", headings[index].textContent.trim());
          }
        });
      });
      table.classList.add("olr-account-table");
    });
  }

  function initializeAffiliateCode(hub) {
    var setup = hub.querySelector("[data-olr-code-setup]");
    if (!setup || !window.fetch) { return; }
    var form = setup.querySelector("form");
    var message = setup.querySelector("[data-olr-code-message]");
    var button = form.querySelector("button");
    var pending = false;
    function prepare() {
      if (pending) { return; }
      pending = true;
      button.disabled = true;
      message.textContent = "Preparing your referral code…";
      var body = new FormData(form);
      body.delete("olr_aff_action");
      body.set("action", "olr_prepare_affiliate_code");
      fetch(setup.getAttribute("data-endpoint"), { method: "POST", credentials: "same-origin", body: body })
        .then(function (response) { return response.json(); })
        .then(function (result) {
          if (!result.success || !result.data || !result.data.code) {
            throw new Error(result.data && result.data.message || "Your code could not be prepared. Retry or contact support.");
          }
          hub.querySelector("[data-olr-code-value]").textContent = result.data.code;
          var copy = hub.querySelector("[data-olr-code-copy]");
          copy.setAttribute("data-copy-value", result.data.code);
          copy.hidden = false;
          setup.hidden = true;
        })
        .catch(function (error) { message.textContent = error.message || "Please retry preparing your code."; })
        .finally(function () { pending = false; button.disabled = false; });
    }
    form.addEventListener("submit", function (event) { event.preventDefault(); prepare(); });
    prepare();
  }

  function restyleAffiliateCharts(hub) {
    window.setTimeout(function () {
      if (!window.Chart) {
        return;
      }

      var charts = [];
      if (window.Chart.instances) {
        Object.keys(window.Chart.instances).forEach(function (key) {
          charts.push(window.Chart.instances[key]);
        });
      }

      hub.querySelectorAll("canvas.uap-canvas").forEach(function (canvas) {
        if (typeof window.Chart.getChart === "function") {
          var chart = window.Chart.getChart(canvas);
          if (chart && charts.indexOf(chart) === -1) {
            charts.push(chart);
          }
        }
      });

      charts.forEach(function (chart) {
        if (!chart || !chart.canvas || !hub.contains(chart.canvas) || !chart.data || !chart.data.datasets) {
          return;
        }

        chart.data.datasets.forEach(function (dataset) {
          dataset.backgroundColor = "rgba(17, 17, 17, 0.06)";
          dataset.borderColor = "#111111";
          dataset.pointBackgroundColor = "#111111";
          dataset.pointBorderColor = "#111111";
        });
        chart.update(0);
      });
    }, 0);
  }

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("[data-olr-account-hub], [data-olr-affiliate-public]").forEach(function (hub) {
      if (hub.getAttribute("data-olr-account-initialized") === "true") {
        return;
      }
      hub.setAttribute("data-olr-account-initialized", "true");

      var status = document.createElement("span");
      status.className = "olr-account-copy-status";
      status.setAttribute("aria-live", "polite");
      hub.appendChild(status);
      initializeCopyButtons(hub, status);
      if (hub.matches("[data-olr-account-hub]")) {
        document.body.classList.add("olr-account-hub-page");
        initializeNavigation(hub);
        initializeTables(hub);
        initializeAffiliateCode(hub);
        restyleAffiliateCharts(hub);
      }
    });
  });
})();
