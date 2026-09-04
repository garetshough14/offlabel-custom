(function () {
  "use strict";

  var STORAGE_KEY = "olr-research-access-v2";

  function initResearchAccessGate() {
    var gate = document.querySelector("[data-olr-compliance-gate]");

    if (!gate || gate.dataset.olrGateInitialized === "true") {
      return;
    }

    var confirmation = gate.querySelector("[data-olr-gate-confirmation]");
    var enterButton = gate.querySelector("[data-olr-gate-enter]");
    var gatePanel = gate.querySelector(".olr-compliance-gate__panel");
    var confirmationLabel = gate.querySelector(".olr-compliance-gate__confirmation");

    if (!confirmation && confirmationLabel) {
      confirmation = document.createElement("input");
      confirmation.type = "checkbox";
      confirmation.required = true;
      confirmation.setAttribute("aria-required", "true");
      confirmation.setAttribute("data-olr-gate-confirmation", "");
      confirmationLabel.insertBefore(confirmation, confirmationLabel.firstChild);
    }

    if (!confirmation || !enterButton || !gatePanel) {
      return;
    }

    gate.dataset.olrGateInitialized = "true";

    function hasCurrentAcceptance() {
      try {
        return window.sessionStorage.getItem(STORAGE_KEY) === "accepted";
      } catch (error) {
        return false;
      }
    }

    function dismissGate() {
      try {
        if (typeof gate.close === "function" && gate.open) {
          gate.close();
        } else {
          gate.removeAttribute("open");
        }
      } catch (error) {
        gate.removeAttribute("open");
      }

      gate.style.display = "none";
      document.documentElement.classList.remove("olr-gate-open");
    }

    if (hasCurrentAcceptance()) {
      dismissGate();
      return;
    }

    function openGate() {
      gate.style.removeProperty("display");
      document.documentElement.classList.add("olr-gate-open");

      if (typeof gate.showModal === "function") {
        try {
          if (gate.open) {
            gate.close();
          }
          gate.showModal();
        } catch (error) {
          gate.setAttribute("open", "");
        }
      } else {
        gate.setAttribute("open", "");
      }

      window.requestAnimationFrame(function () {
        try {
          confirmation.focus({ preventScroll: true });
        } catch (error) {
          confirmation.focus();
        }
      });
    }

    function updateConfirmationState() {
      gatePanel.toggleAttribute("data-confirmed", confirmation.checked);
      enterButton.setAttribute("aria-disabled", confirmation.checked ? "false" : "true");
    }

    confirmation.addEventListener("change", updateConfirmationState);

    enterButton.addEventListener("click", function (event) {
      event.preventDefault();

      if (!confirmation.checked) {
        confirmation.focus();
        confirmation.reportValidity();
        return;
      }

      try {
        window.sessionStorage.setItem(STORAGE_KEY, "accepted");
      } catch (error) {
        /* Storage is optional; the visitor can still enter. */
      }

      dismissGate();
    });

    gate.addEventListener("cancel", function (event) {
      event.preventDefault();
    });

    updateConfirmationState();
    openGate();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initResearchAccessGate, { once: true });
  } else {
    initResearchAccessGate();
  }
})();
