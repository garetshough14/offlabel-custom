(function () {
  "use strict";

  function initFaq(root) {
    var searchForm = root.querySelector(".olr-faq-search");
    var searchInput = root.querySelector("#olr-faq-search-input");
    var searchStatus = root.querySelector(".olr-faq-search__status");
    var emptyState = root.querySelector(".olr-faq-empty");
    var groups = Array.prototype.slice.call(root.querySelectorAll(".olr-faq-group"));
    var categoryButtons = Array.prototype.slice.call(root.querySelectorAll("[data-faq-target]"));
    var reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    if (!searchForm || !searchInput || !groups.length) {
      return;
    }

    function setGroupOpen(group, open) {
      var heading = group.querySelector(".olr-faq-group__heading");
      var questions = group.querySelector(".olr-faq-group__questions");

      group.classList.toggle("is-open", open);
      heading.setAttribute("aria-expanded", open ? "true" : "false");
      questions.hidden = !open;
    }

    function setActiveCategory(category) {
      categoryButtons.forEach(function (button) {
        var active = button.getAttribute("data-faq-target") === category;
        button.classList.toggle("is-active", active);
        button.setAttribute("aria-pressed", active ? "true" : "false");
      });
    }

    function clearSearch() {
      searchInput.value = "";
      groups.forEach(function (group) {
        group.hidden = false;
        group.querySelectorAll("details").forEach(function (question) {
          question.hidden = false;
        });
      });
      emptyState.hidden = true;
      searchStatus.textContent = "";
    }

    function showCategory(category, shouldScroll) {
      var target = root.querySelector('[data-faq-category="' + category + '"]');

      if (!target) {
        return;
      }

      clearSearch();
      groups.forEach(function (group) {
        setGroupOpen(group, group === target);
      });
      setActiveCategory(category);

      if (shouldScroll) {
        target.scrollIntoView({ behavior: reduceMotion ? "auto" : "smooth", block: "start" });
      }
    }

    function filterQuestions() {
      var query = searchInput.value.trim().toLowerCase();
      var matches = 0;

      if (!query) {
        clearSearch();
        groups.forEach(function (group, index) {
          setGroupOpen(group, index === 0);
        });
        setActiveCategory("products");
        return;
      }

      categoryButtons.forEach(function (button) {
        button.classList.remove("is-active");
        button.setAttribute("aria-pressed", "false");
      });

      groups.forEach(function (group) {
        var groupMatches = 0;

        group.querySelectorAll("details").forEach(function (question) {
          var match = question.textContent.toLowerCase().indexOf(query) !== -1;
          question.hidden = !match;
          if (match) {
            groupMatches += 1;
            matches += 1;
          }
        });

        group.hidden = groupMatches === 0;
        setGroupOpen(group, groupMatches > 0);
      });

      emptyState.hidden = matches !== 0;
      searchStatus.textContent = matches === 1 ? "1 matching question" : matches + " matching questions";
    }

    categoryButtons.forEach(function (button) {
      button.addEventListener("click", function () {
        showCategory(button.getAttribute("data-faq-target"), true);
      });
    });

    groups.forEach(function (group) {
      var heading = group.querySelector(".olr-faq-group__heading");

      heading.addEventListener("click", function () {
        var willOpen = !group.classList.contains("is-open");
        setGroupOpen(group, willOpen);
        if (willOpen) {
          setActiveCategory(group.getAttribute("data-faq-category"));
        }
      });

      group.querySelectorAll("details").forEach(function (question) {
        question.addEventListener("toggle", function () {
          if (!question.open) {
            return;
          }
          group.querySelectorAll("details[open]").forEach(function (other) {
            if (other !== question) {
              other.open = false;
            }
          });
        });
      });
    });

    searchForm.addEventListener("submit", function (event) {
      event.preventDefault();
      filterQuestions();
    });

    searchInput.addEventListener("input", filterQuestions);
    searchInput.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        clearSearch();
        groups.forEach(function (group, index) {
          setGroupOpen(group, index === 0);
        });
        setActiveCategory("products");
      }
    });
  }

  document.querySelectorAll("[data-olr-faq]").forEach(initFaq);
})();
