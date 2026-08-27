(function () {
  "use strict";
  var root = document.querySelector("[data-olr-coa-viewer]");
  if (!root) return;
  var url = root.dataset.pdfUrl;
  var canvas = root.querySelector("[data-pdf-canvas]");
  var stage = root.querySelector("[data-pdf-stage]");
  var status = root.querySelector("[data-pdf-status]");
  var pageInput = root.querySelector("[data-pdf-page]");
  var pages = root.querySelector("[data-pdf-pages]");
  var zoomOutput = root.querySelector("[data-pdf-zoom]");
  var pdf, pageNumber = 1, scale = 1.25, rotation = 0, rendering = false, queued = null;

  function render(number) {
    if (!pdf || rendering) { queued = number; return; }
    rendering = true;
    pdf.getPage(number).then(function (page) {
      var viewport = page.getViewport({ scale: scale, rotation: rotation });
      var ratio = window.devicePixelRatio || 1;
      canvas.width = Math.floor(viewport.width * ratio);
      canvas.height = Math.floor(viewport.height * ratio);
      canvas.style.width = viewport.width + "px";
      canvas.style.height = viewport.height + "px";
      return page.render({ canvasContext: canvas.getContext("2d"), viewport: viewport, transform: ratio === 1 ? null : [ratio, 0, 0, ratio, 0, 0] }).promise;
    }).then(function () {
      rendering = false; status.hidden = true; pageInput.value = pageNumber; zoomOutput.value = Math.round(scale / 1.25 * 100) + "%";
      if (queued !== null) { var next = queued; queued = null; render(next); }
    }).catch(function () { status.textContent = "Unable to display this report. Use View original report above."; rendering = false; });
  }
  function show(number) { pageNumber = Math.max(1, Math.min(pdf.numPages, number)); render(pageNumber); }
  function button(selector, handler) { var el = root.querySelector(selector); if (el) el.addEventListener("click", handler); }

  button("[data-pdf-prev]", function () { show(pageNumber - 1); });
  button("[data-pdf-zoom-out]", function () { scale = Math.max(.6, scale - .15); render(pageNumber); });
  button("[data-pdf-zoom-in]", function () { scale = Math.min(3, scale + .15); render(pageNumber); });
  button("[data-pdf-rotate]", function () { rotation = (rotation + 90) % 360; render(pageNumber); });
  button("[data-pdf-fullscreen]", function () { if (stage.requestFullscreen) stage.requestFullscreen(); });
  button("[data-pdf-print]", function () { var frame = document.createElement("iframe"); frame.hidden = true; frame.src = url; document.body.appendChild(frame); frame.onload = function () { frame.contentWindow.print(); }; });
  pageInput.addEventListener("change", function () { show(parseInt(pageInput.value, 10) || 1); });

  import(window.olrCoaViewer.libraryUrl).then(function (pdfjsLib) {
    pdfjsLib.GlobalWorkerOptions.workerSrc = window.olrCoaViewer.workerUrl;
    return pdfjsLib.getDocument(url).promise;
  }).then(function (documentPdf) {
    pdf = documentPdf; pages.textContent = pdf.numPages; pageInput.max = pdf.numPages; render(1);
  }).catch(function () { status.textContent = "Unable to display this report. Use View original report above."; });
})();
