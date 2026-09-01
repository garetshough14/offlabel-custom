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
    if (!account || !side) {
      return;
    }

    var toggle = document.createElement("button");
    var label = window.olrAccountHub && olrAccountHub.menuLabel ? olrAccountHub.menuLabel : "Account menu";
    toggle.type = "button";
    toggle.className = "olr-account-menu-toggle";
    toggle.setAttribute("aria-expanded", "false");
    toggle.innerHTML = "<span>" + label + "</span><span aria-hidden=\"true\">+</span>";
    account.classList.add("olr-account-has-toggle");
    account.insertBefore(toggle, side);

    toggle.addEventListener("click", function () {
      var open = account.classList.toggle("olr-account-nav-open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      toggle.lastElementChild.textContent = open ? "−" : "+";
    });

    side.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function () {
        account.classList.remove("olr-account-nav-open");
        toggle.setAttribute("aria-expanded", "false");
        toggle.lastElementChild.textContent = "+";
      });
    });

    var logoutUrl = window.olrAccountHub && olrAccountHub.logoutUrl ? olrAccountHub.logoutUrl : "";
    if (logoutUrl) {
      hub.querySelectorAll('a[href*="um_tab=olr_logout"], .um-account-link[data-tab="olr_logout"] a').forEach(function (link) {
        link.href = logoutUrl;
        link.addEventListener("click", function (event) {
          event.preventDefault();
          window.location.assign(logoutUrl);
        });
      });
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("[data-olr-account-hub]").forEach(function (hub) {
      var status = document.createElement("span");
      status.className = "olr-account-copy-status";
      status.setAttribute("aria-live", "polite");
      hub.appendChild(status);
      initializeCopyButtons(hub, status);
      initializeNavigation(hub);
    });
  });
})();

