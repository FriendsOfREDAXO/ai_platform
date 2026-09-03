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

    // Fill the datalist behind the model field with what the provider's catalog offers
    // for this type. Suggestions only -- the field stays free text, because the
    // catalogs miss working model names (see ProviderRegistry::models()).
    function updateSuggestions() {
        var list = document.getElementById("ai-model-suggestions");
        if (!list) return;

        var models = (configFor(providerSelect.value).models || {})[typeSelect.value] || [];
        list.innerHTML = "";
        for (var i = 0; i < models.length; i++) {
            var option = document.createElement("option");
            option.value = models[i];
            list.appendChild(option);
        }
    }

    function updateModel() {
        if (!modelInput || modelManuallyEdited) return;

        var model = (configFor(providerSelect.value).defaults || {})[typeSelect.value] || "";
        modelInput.value = model;
        lastAutoModel = model;
    }

    function onSelectionChange() {
        // If model was auto-filled, allow changing it when type or provider changes
        if (modelInput && modelInput.value === lastAutoModel) {
            modelManuallyEdited = false;
        }
        updateVisibility();
        updateSuggestions();
        updateModel();
    }

    typeSelect.addEventListener("change", onSelectionChange);
    providerSelect.addEventListener("change", onSelectionChange);

    // Initial update (visibility only, don't overwrite existing model in edit mode)
    updateVisibility();
    updateSuggestions();

    // Only auto-fill model if the field is empty (add mode)
    if (modelInput && modelInput.value === "") {
        updateModel();
    }
});
