(function ($) {
  "use strict";
  $(function () {
    var frame;
    $("#olr-coa-pdf-select").on("click", function () {
      frame = frame || wp.media({ title: "Choose certificate PDF", library: { type: "application/pdf" }, multiple: false });
      frame.off("select").on("select", function () {
        var file = frame.state().get("selection").first().toJSON();
        $("#olr-coa-pdf-id").val(file.id);
        $("#olr-coa-pdf-name").text(file.filename);
      }).open();
    });
    $("#olr-coa-pdf-remove").on("click", function () {
      $("#olr-coa-pdf-id").val("");
      $("#olr-coa-pdf-name").text("No PDF selected");
    });
  });
})(jQuery);
