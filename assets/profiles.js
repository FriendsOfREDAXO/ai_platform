$(document).on("rex:ready", function () {
    var typeSelect = document.getElementById("ai-type-select");
    var providerSelect = document.getElementById("ai-provider-select");
    if (!typeSelect || !providerSelect) return;

    // Which fields are visible per type
    var typeFields = {
        text: ["temperature", "max_tokens", "system_prompt"],
        image_generation: ["image_size", "image_quality", "image_style"],
        image_understanding: ["temperature", "max_tokens", "detail_level"],
        embedding: [],
    };

    var allTypeFields = [
        "temperature",
        "max_tokens",
        "system_prompt",
        "image_size",
        "image_quality",
        "image_style",
        "detail_level",
    ];

    // Fields whose visibility depends on the provider, not on the type alone.
    var credentialFields = ["api_key", "base_url"];
    // Provider options that live inside a type block: image_quality and image_style are
    // DALL-E settings, so they need the type to show them AND the provider to have them.
    var providerOptionFields = ["image_quality", "image_style"];

    // Everything provider-specific -- which fields to show, the default model per type
    // and the model suggestions -- comes from PHP (ProviderRegistry::formConfig(),
    // emitted by pages/profiles.php). This file deliberately knows no provider names:
    // the previous copy of that knowledge here is what kept the Ollama API-key field
    // hidden long after the PHP side had started supporting it.
    var providerConfig = readProviderConfig();

    function readProviderConfig() {
        var node = document.getElementById("ai-provider-config");
        if (!node) return {};
        try {
            return JSON.parse(node.textContent) || {};
        } catch (e) {
            return {};
        }
    }

    function configFor(provider) {
        // Unknown provider (added by an extension point that failed to render, say):
        // show both credential fields rather than hiding what might be required.
        return providerConfig[provider] || { fields: credentialFields, defaults: {}, models: {} };
    }

    function getFieldRow(suffix) {
        var input = document.querySelector("[name$='[" + suffix + "]']");
        return input ? input.closest("dl.rex-form-group") : null;
    }

    function getFieldInput(suffix) {
        return document.querySelector("[name$='[" + suffix + "]']");
    }

    // The model picker is two controls over one column: the select offers the
    // provider's catalog, the input takes a name the catalog does not have. The input
    // is the field bound to `model` and stays the single source of truth -- the select
    // only writes into it.
    var modelSelect = document.getElementById("ai-model-select");
    var CUSTOM_MODEL = "__custom__";

    // Track whether user has manually edited the model field
    var modelInput = getFieldInput("model");
    var modelManuallyEdited = false;
    var lastAutoModel = modelInput ? modelInput.value : "";

    if (modelInput) {
        modelInput.addEventListener("input", function () {
            modelManuallyEdited = true;
        });
    }

    function updateVisibility() {
        var type = typeSelect.value;
        var visibleType = typeFields[type] || [];
        var fields = configFor(providerSelect.value).fields || [];

        // Type-specific fields
        for (var i = 0; i < allTypeFields.length; i++) {
            var field = allTypeFields[i];
            var row = getFieldRow(field);
            if (!row) continue;

            var show = visibleType.indexOf(field) >= 0;
            if (show && providerOptionFields.indexOf(field) >= 0) {
                show = fields.indexOf(field) >= 0;
            }

            row.style.display = show ? "" : "none";
        }

        // Provider credentials (api_key, base_url)
        for (var j = 0; j < credentialFields.length; j++) {
            var credentialRow = getFieldRow(credentialFields[j]);
            if (credentialRow) {
                credentialRow.style.display = fields.indexOf(credentialFields[j]) >= 0 ? "" : "none";
            }
        }
    }

    function catalogModels() {
        return (configFor(providerSelect.value).models || {})[typeSelect.value] || [];
    }

    // What to hide when there is nothing to pick. bootstrap-select (be_style initialises
    // every .selectpicker on rex:ready) wraps the select in a .bootstrap-select div and
    // hides the original element itself, so hiding the select would hide nothing visible.
    // Without the plugin -- in a test harness -- the select is its own box.
    function modelPickerBox() {
        return (modelSelect && modelSelect.closest("div.bootstrap-select")) || modelSelect;
    }

    // bootstrap-select renders its own markup once and does not watch the option list, so
    // every change to the options or the value has to be announced.
    function refreshModelSelect() {
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.selectpicker) {
            window.jQuery(modelSelect).selectpicker("refresh");
        }
    }

    // Show the free-text input only while the name cannot come from the select.
    function setCustomModel(on) {
        if (modelInput) modelInput.style.display = on ? "" : "none";
    }

    // Rebuild the select for the current provider and type. An empty catalog -- the
    // OpenAI-compatible provider, or a type the provider has no models for -- hides the
    // select entirely instead of offering a list of one entry.
    function updateModelSelect() {
        if (!modelSelect) return;

        var models = catalogModels();
        var box = modelPickerBox();
        modelSelect.innerHTML = "";

        if (models.length === 0) {
            box.style.display = "none";
            refreshModelSelect();
            setCustomModel(true);
            return;
        }

        box.style.display = "";
        for (var i = 0; i < models.length; i++) {
            modelSelect.appendChild(new Option(models[i], models[i]));
        }
        modelSelect.appendChild(new Option(
            modelSelect.getAttribute("data-custom-label") || "custom",
            CUSTOM_MODEL,
        ));

        syncModelSelect();
    }

    // Take the select's state from the input, never the other way round: a stored model
    // the catalog does not list (llama3.2-vision, anything on a self-hosted server) has
    // to survive opening and saving the profile, so it switches the picker to the custom
    // entry rather than being silently replaced by the first option.
    function syncModelSelect() {
        if (!modelSelect || !modelInput) return;

        var known = catalogModels().indexOf(modelInput.value) >= 0;
        modelSelect.value = known ? modelInput.value : CUSTOM_MODEL;
        refreshModelSelect();
        setCustomModel(!known);
    }

    if (modelSelect) {
        modelSelect.addEventListener("change", function () {
            if (modelSelect.value === CUSTOM_MODEL) {
                setCustomModel(true);
                if (modelInput) modelInput.focus();
                return;
            }

            if (modelInput) modelInput.value = modelSelect.value;
            // A deliberate pick counts as editing, same as typing: it must not be
            // overwritten by the default model on the next type change.
            modelManuallyEdited = true;
            setCustomModel(false);
        });
    }

    function updateModel() {
        if (!modelInput || modelManuallyEdited) return;

        var model = (configFor(providerSelect.value).defaults || {})[typeSelect.value] || "";
        modelInput.value = model;
        lastAutoModel = model;
    }

    // A model only ever belongs to one provider and one type, so after switching either
    // of them a leftover name is wrong -- gpt-4o-mini does not become an embedding model
    // by picking the embedding type. If the new pairing has a catalog, the value is
    // replaced by its default (or its first entry when the default does not fit either).
    // An empty catalog is left alone: there the typed name is the only source there is.
    function ensureModelFitsSelection() {
        if (!modelInput) return;

        var models = catalogModels();
        if (models.length === 0 || models.indexOf(modelInput.value) >= 0) return;

        var fallback = (configFor(providerSelect.value).defaults || {})[typeSelect.value] || "";
        if (models.indexOf(fallback) < 0) {
            fallback = models[0];
        }

        modelInput.value = fallback;
        lastAutoModel = fallback;
        modelManuallyEdited = false;
    }

    function onSelectionChange() {
        // If model was auto-filled, allow changing it when type or provider changes
        if (modelInput && modelInput.value === lastAutoModel) {
            modelManuallyEdited = false;
        }
        updateVisibility();
        updateModel();
        ensureModelFitsSelection();
        updateModelSelect();
    }

    typeSelect.addEventListener("change", onSelectionChange);
    providerSelect.addEventListener("change", onSelectionChange);

    // Initial update (visibility only, don't overwrite existing model in edit mode)
    updateVisibility();

    // Only auto-fill model if the field is empty (add mode)
    if (modelInput && modelInput.value === "") {
        updateModel();
    }

    updateModelSelect();
});
