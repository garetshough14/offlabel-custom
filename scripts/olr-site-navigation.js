(function () {
  "use strict";

  var mobileQuery = window.matchMedia("(max-width: 56rem)");
  var lockedScrollY = 0;

  function normalizedPath(value) {
    try {
      var path = new URL(value, window.location.origin).pathname.replace(/\/+$/, "");
      return path || "/";
    } catch (error) {
      return "/";
    }
  }

  function setCurrentLinks(header) {
    var current = normalizedPath(window.location.href);
    var aliases = {
      "/research": "/shop",
      "/testing": "/coas"
    };

    current = aliases[current] || current;
    header.querySelectorAll("a[href]").forEach(function (link) {
      var target = normalizedPath(link.href);
      target = aliases[target] || target;
      if (target === current || (target !== "/" && current.indexOf(target + "/") === 0)) {
        link.setAttribute("aria-current", "page");
      } else {
        link.removeAttribute("aria-current");
      }
    });
  }

  function lockPage() {
    if (document.body.classList.contains("olr-mobile-nav-open")) {
      return;
    }

    lockedScrollY = window.scrollY || window.pageYOffset || 0;
    document.body.style.setProperty("--olr-nav-scroll-y", "-" + lockedScrollY + "px");
    document.body.classList.add("olr-mobile-nav-open");
  }

  function unlockPage() {
    if (!document.body.classList.contains("olr-mobile-nav-open")) {
      return;
    }

    document.body.classList.remove("olr-mobile-nav-open");
    document.body.style.removeProperty("--olr-nav-scroll-y");
    window.scrollTo(0, lockedScrollY);
  }

  function focusableItems(drawer) {
    return Array.prototype.slice.call(
      drawer.querySelectorAll("summary, a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex='-1'])")
    ).filter(function (item) {
      return item.getClientRects().length > 0;
    });
  }

  function initializeDrawer(header) {
    var drawer = header.querySelector("[data-olr-nav-drawer]");
    var toggle = drawer && drawer.querySelector("[data-olr-nav-toggle]");
    var panel = drawer && drawer.querySelector("[data-olr-nav-panel]");
    var toggleLabel = drawer && drawer.querySelector("[data-olr-nav-label]");

    if (!drawer || !toggle || !panel) {
      return;
    }

    function closeDrawer(returnFocus) {
      if (!drawer.open) {
        return;
      }

      drawer.open = false;
      toggle.setAttribute("aria-expanded", "false");
      if (toggleLabel) {
        toggleLabel.textContent = "Open menu";
      }
      unlockPage();
      if (returnFocus) {
        toggle.focus({ preventScroll: true });
      }
    }

    drawer.addEventListener("toggle", function () {
      var open = drawer.open && mobileQuery.matches;
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      if (toggleLabel) {
        toggleLabel.textContent = open ? "Close menu" : "Open menu";
      }
      if (open) {
        lockPage();
        window.setTimeout(function () {
          if (!drawer.open) {
            return;
          }
          var firstLink = panel.querySelector("a[href]");
          if (firstLink) {
            firstLink.focus({ preventScroll: true });
          }
        }, 180);
      } else {
        unlockPage();
      }
    });

    drawer.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        event.preventDefault();
        closeDrawer(true);
        return;
      }

      if (event.key !== "Tab" || !drawer.open) {
        return;
      }

      var items = focusableItems(drawer);
      if (!items.length) {
        return;
      }

      var first = items[0];
      var last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    panel.addEventListener("click", function (event) {
      if (event.target.closest("a[href]")) {
        closeDrawer(false);
      }
    });

    document.addEventListener("pointerdown", function (event) {
      if (drawer.open && !drawer.contains(event.target)) {
        closeDrawer(false);
      }
    });

    function handleViewportChange(event) {
      if (!event.matches) {
        closeDrawer(false);
      }
    }

    if (typeof mobileQuery.addEventListener === "function") {
      mobileQuery.addEventListener("change", handleViewportChange);
    } else if (typeof mobileQuery.addListener === "function") {
      mobileQuery.addListener(handleViewportChange);
    }
  }

  function initialize() {
    document.querySelectorAll(".olr-managed-header").forEach(function (header) {
      setCurrentLinks(header);
      initializeDrawer(header);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initialize, { once: true });
  } else {
    initialize();
  }
})();
