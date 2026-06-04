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

    // Which provider-specific fields to show/hide
    // base_url: only Ollama
    // api_key: everything except Ollama
    // image_quality, image_style: only OpenAI (DALL-E specific)
    var providerFields = {
        openai: { api_key: true, base_url: false, image_quality: true, image_style: true },
        anthropic: { api_key: true, base_url: false, image_quality: false, image_style: false },
        google: { api_key: true, base_url: false, image_quality: false, image_style: false },
        ollama: { api_key: false, base_url: true, image_quality: false, image_style: false },
    };

    // Default models per provider+type
    var defaultModels = {
        openai: {
            text: "gpt-4o",
            image_generation: "dall-e-3",
            image_understanding: "gpt-4o",
            embedding: "text-embedding-3-small",
        },
        anthropic: {
            text: "claude-sonnet-4-20250514",
            image_generation: "",
            image_understanding: "claude-sonnet-4-20250514",
            embedding: "",
        },
        google: {
            text: "gemini-2.5-flash",
            image_generation: "gemini-2.0-flash-exp",
            image_understanding: "gemini-2.5-flash",
            embedding: "text-embedding-004",
        },
        ollama: {
            text: "llama3.2",
            image_generation: "",
            image_understanding: "llava",
            embedding: "nomic-embed-text",
        },
    };

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
        var provider = providerSelect.value;
        var visibleType = typeFields[type] || [];
        var pf = providerFields[provider] || {
            api_key: true,
            base_url: false,
            image_quality: false,
            image_style: false,
        };

        // Type-specific fields
        for (var i = 0; i < allTypeFields.length; i++) {
            var field = allTypeFields[i];
            var row = getFieldRow(field);
            if (!row) continue;

            var showByType = visibleType.indexOf(field) >= 0;

            // image_quality and image_style: additionally check provider
            if (field === "image_quality" || field === "image_style") {
                row.style.display = showByType && pf[field] ? "" : "none";
            } else {
                row.style.display = showByType ? "" : "none";
            }
        }

        // Provider-specific fields (api_key, base_url)
        var apiKeyRow = getFieldRow("api_key");
        var baseUrlRow = getFieldRow("base_url");
        if (apiKeyRow) apiKeyRow.style.display = pf.api_key ? "" : "none";
        if (baseUrlRow) baseUrlRow.style.display = pf.base_url ? "" : "none";
    }

    function updateModel() {
        if (!modelInput || modelManuallyEdited) return;

        var provider = providerSelect.value;
        var type = typeSelect.value;
        var models = defaultModels[provider];
        if (!models) return;

        var model = models[type] || "";
        modelInput.value = model;
        lastAutoModel = model;
    }

    function onTypeChange() {
        // If model was auto-filled, allow changing it when type changes
        if (modelInput && modelInput.value === lastAutoModel) {
            modelManuallyEdited = false;
        }
        updateVisibility();
        updateModel();
    }

    function onProviderChange() {
        // If model was auto-filled, allow changing it when provider changes
        if (modelInput && modelInput.value === lastAutoModel) {
            modelManuallyEdited = false;
        }
        updateVisibility();
        updateModel();
    }

    typeSelect.addEventListener("change", onTypeChange);
    providerSelect.addEventListener("change", onProviderChange);

    // Initial update (visibility only, don't overwrite existing model in edit mode)
    updateVisibility();

    // Only auto-fill model if the field is empty (add mode)
    if (modelInput && modelInput.value === "") {
        updateModel();
    }
});
