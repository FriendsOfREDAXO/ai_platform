/**
 * Select-all for the change request inbox.
 *
 * The row checkboxes sit inside the rex_list table but belong to the bulk form
 * below it, wired up through the HTML5 `form` attribute. There is therefore no
 * enclosing form to scope to, and the lookup goes by class.
 *
 * rex:ready is a jQuery event and fires again after every pjax navigation, so
 * the handler must not assume a fresh document.
 */
$(document).on("rex:ready", function (event, container) {
    var scope = container && container.length ? container : $(document);
    var toggle = scope.find("#ai-change-select-all");

    if (!toggle.length) {
        return;
    }

    toggle.off("change.aiChanges").on("change.aiChanges", function () {
        scope.find(".ai-change-select").prop("checked", toggle.prop("checked"));
    });
});

/**
 * Greys out an allow list while its "allow all" checkbox is ticked.
 *
 * The list stays in the DOM and keeps submitting, so turning the switch back off
 * restores the previous selection instead of an empty one.
 */
$(document).on("rex:ready", function (event, container) {
    var scope = container && container.length ? container : $(document);

    [
        // The module allow list is gone: a module that runs its slice values as
        // PHP is refused by reading its own output, which needs no checkbox.
        ["#ai-allow-all-yform", "#ai-yform-list"]
    ].forEach(function (pair) {
        var toggle = scope.find(pair[0]);
        var list = scope.find(pair[1]);

        if (!toggle.length || !list.length) {
            return;
        }

        var sync = function () {
            list.toggleClass("ai-list-disabled", toggle.prop("checked"));
        };

        toggle.off("change.aiAllowAll").on("change.aiAllowAll", sync);
        sync();
    });
});

/**
 * Reveals the change-request detail settings when the master switch is active.
 *
 * The switch decides whether the feature exists at all, so its settings have no
 * business on screen while it is off. Doing it here rather than server-side means
 * the reveal is immediate: flip the select and the fields are there, no save
 * round-trip first.
 *
 * Consequently the fields are always in the DOM and always in the POST. That is
 * what makes it safe — a hidden field submits the value it was rendered with, so
 * saving while the block is out of sight writes the stored values back unchanged.
 * Conditional rendering would drop them from the POST instead, and the page's post
 * handler would then reset the stale policy and empty the YForm allow list without
 * saying a word. See the note at the top of pages/settings.php.
 *
 * The server sets the initial `hidden`, so there is nothing to hide on first paint
 * and no flash of settings for a feature that is off.
 */
$(document).on("rex:ready", function (event, container) {
    var scope = container && container.length ? container : $(document);
    var master = scope.find("#changes-enabled");
    var blocks = scope.find("[data-ai-changes-detail]");

    if (!master.length || !blocks.length) {
        return;
    }

    var sync = function () {
        blocks.prop("hidden", "1" !== master.val());
    };

    // `change` covers the plain select and is what bootstrap-select triggers on
    // the underlying element; `changed.bs.select` is the belt for the case where
    // it does not. Namespaced so a pjax re-run rebinds instead of stacking.
    master
        .off("change.aiChangesMaster changed.bs.select.aiChangesMaster")
        .on("change.aiChangesMaster changed.bs.select.aiChangesMaster", sync);

    sync();
});
