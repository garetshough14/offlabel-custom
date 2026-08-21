(function () {
  "use strict";

  var STORAGE_KEY = "olr-research-access-v2";

  function initResearchAccessGate() {
    var gate = document.querySelector("[data-olr-compliance-gate]");

    if (!gate) {
      return;
    }

    var confirmation = gate.querySelector("[data-olr-gate-confirmation]");
    var enterButton = gate.querySelector("[data-olr-gate-enter]");
    var gateForm = gate.querySelector(".olr-compliance-gate__panel");

    if (!confirmation || !enterButton || !gateForm) {
      return;
    }

    function hasCurrentAcceptance() {
      try {
        return window.sessionStorage.getItem(STORAGE_KEY) === "accepted";
      } catch (error) {
        return false;
      }
    }

    if (hasCurrentAcceptance()) {
      if (typeof gate.close === "function" && gate.open) {
        gate.close();
      } else {
        gate.removeAttribute("open");
      }
      return;
    }

    function openGate() {
      document.documentElement.classList.add("olr-gate-open");

      if (typeof gate.showModal === "function") {
        if (gate.open) {
          gate.close();
        }
        gate.showModal();
      } else {
        gate.setAttribute("open", "");
      }

      window.requestAnimationFrame(function () {
        confirmation.focus({ preventScroll: true });
      });
    }

    confirmation.addEventListener("change", function () {
      gateForm.toggleAttribute("data-confirmed", confirmation.checked);
    });

    gateForm.addEventListener("submit", function (event) {
      event.preventDefault();

      if (!confirmation.checked) {
        confirmation.reportValidity();
        return;
      }

      try {
        window.sessionStorage.setItem(STORAGE_KEY, "accepted");
      } catch (error) {
        /* Visitors can still enter when storage is unavailable. */
      }

      if (typeof gate.close === "function") {
        gate.close();
      } else {
        gate.removeAttribute("open");
      }

      document.documentElement.classList.remove("olr-gate-open");
    });

    gate.addEventListener("cancel", function (event) {
      event.preventDefault();
    });

    openGate();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initResearchAccessGate, { once: true });
  } else {
    initResearchAccessGate();
  }
})();
