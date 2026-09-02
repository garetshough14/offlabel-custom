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

    var brand = hub.querySelector(":scope > .olr-account-brand");
    if (brand) {
      side.insertBefore(brand, side.firstChild);
    }

    var toggle = document.createElement("button");
    var label = window.olrAccountHub && olrAccountHub.menuLabel ? olrAccountHub.menuLabel : "Account menu";
    toggle.type = "button";
    toggle.className = "olr-account-menu-toggle";
    toggle.setAttribute("aria-expanded", "false");
    toggle.innerHTML = "<span>" + label + "</span><span aria-hidden=\"true\">+</span>";
    account.classList.add("olr-account-has-toggle");
    navigationShell.insertBefore(toggle, side);

    toggle.addEventListener("click", function () {
      var open = account.classList.toggle("olr-account-nav-open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      toggle.lastElementChild.textContent = open ? "\u2212" : "+";
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
      var status = document.createElement("span");
      status.className = "olr-account-copy-status";
      status.setAttribute("aria-live", "polite");
      hub.appendChild(status);
      initializeCopyButtons(hub, status);
      if (hub.matches("[data-olr-account-hub]")) {
        document.body.classList.add("olr-account-hub-page");
        initializeNavigation(hub);
        restyleAffiliateCharts(hub);
      }
    });
  });
})();
