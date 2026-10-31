(function () {
    'use strict';

    // Welche Felder ein Typ zeigt. Die anbieterabhängigen (Zugang, Bildoptionen) kommen zusätzlich aus der
    // Anbieter-Konfiguration, die pages/profiles.php aus ProviderRegistry::formConfig() schreibt -- diese Datei kennt
    // bewusst keinen Anbieter.
    var TYPE_FIELDS = {
        text: ['temperature', 'max_tokens', 'system_prompt'],
        image_generation: ['image_size', 'image_quality', 'image_style'],
        image_understanding: ['temperature', 'max_tokens', 'detail_level'],
        embedding: []
    };
    var TYPE_DEPENDENT = ['temperature', 'max_tokens', 'system_prompt', 'image_size', 'image_quality', 'image_style', 'detail_level'];
    var CREDENTIALS = ['api_key', 'base_url'];
    // Bildoptionen gehören zu einem Typ UND müssen vom Anbieter angeboten werden.
    var PROVIDER_OPTIONS = ['image_quality', 'image_style'];

    function readConfig() {
        var node = document.getElementById('ai-provider-config');
        if (!node) {
            return null;
        }
        try {
            return JSON.parse(node.textContent) || {};
        } catch (e) {
            return {};
        }
    }

    function initForm() {
        var config = readConfig();
        var typeSelect = document.querySelector('[name="ai_profile[type]"]');
        var providerSelect = document.querySelector('[name="ai_profile[provider]"]');
        var modelInput = document.querySelector('[name="ai_profile[model]"]');
        var envInput = document.querySelector('[name="ai_profile[apiKeyEnv]"]');
        if (!config || !typeSelect || !providerSelect || !modelInput || typeSelect.dataset.aiReady) {
            return;
        }
        typeSelect.dataset.aiReady = '1';

        // Vorschläge aus dem Modellkatalog des Anbieters, als datalist: ein Name außerhalb des Katalogs bleibt
        // eintragbar -- die Kataloge haben Lücken, und der OpenAI-kompatible Anbieter hat gar keinen.
        var datalist = document.createElement('datalist');
        datalist.id = 'ai-model-suggestions';
        modelInput.setAttribute('list', datalist.id);
        modelInput.setAttribute('autocomplete', 'off');
        modelInput.parentNode.appendChild(datalist);

        var lastAutoModel = modelInput.value;

        function providerConfig() {
            // Unbekannter Anbieter: beide Zugangsfelder zeigen, statt etwas zu verstecken, das nötig sein könnte.
            return config[providerSelect.value] || {fields: CREDENTIALS, defaults: {}, models: {}, env: '', envSet: false};
        }

        function show(field, visible) {
            var wrapper = document.querySelector('[data-ai-field="' + field + '"]');
            if (wrapper) {
                wrapper.hidden = !visible;
            }
        }

        function updateVisibility() {
            var typeFields = TYPE_FIELDS[typeSelect.value] || [];
            var fields = providerConfig().fields || [];
            TYPE_DEPENDENT.forEach(function (field) {
                var visible = typeFields.indexOf(field) >= 0;
                if (visible && PROVIDER_OPTIONS.indexOf(field) >= 0) {
                    visible = fields.indexOf(field) >= 0;
                }
                show(field, visible);
            });
            CREDENTIALS.forEach(function (field) {
                show(field, fields.indexOf(field) >= 0);
            });
            if (envInput) {
                envInput.placeholder = providerConfig().env || '';
            }
        }

        function models() {
            return (providerConfig().models || {})[typeSelect.value] || [];
        }

        function updateSuggestions() {
            datalist.innerHTML = '';
            models().forEach(function (name) {
                datalist.appendChild(new Option(name, name));
            });
        }

        // Ein Modell gehört zu einem Anbieter und einem Typ. Nach einem Wechsel wird ein automatisch gesetzter oder
        // nicht mehr passender Name durch die Vorgabe ersetzt; einen selbst eingetragenen Namen ohne Katalog (eigener
        // Server) lässt das in Ruhe.
        function updateModel() {
            var list = models();
            var fallback = (providerConfig().defaults || {})[typeSelect.value] || list[0] || '';
            var auto = modelInput.value === '' || modelInput.value === lastAutoModel;
            var foreign = list.length > 0 && list.indexOf(modelInput.value) < 0;
            if (auto || foreign) {
                modelInput.value = fallback;
                lastAutoModel = fallback;
            }
        }

        function onChange() {
            updateVisibility();
            updateModel();
            updateSuggestions();
        }

        typeSelect.addEventListener('change', onChange);
        providerSelect.addEventListener('change', onChange);

        updateVisibility();
        updateSuggestions();
        if (modelInput.value === '') {
            updateModel();
        }
    }

    // Verbindungstest: fragt die API-Funktion ai_platform_test und zeigt das Ergebnis als Hinweis.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-ai-test]');
        if (!button) {
            return;
        }
        var box = button.closest('[data-ai-test-url]');
        var running = box.querySelector('.ai-test-running');
        var result = box.querySelector('.ai-test-result');

        function render(success, message, details) {
            var alert = document.createElement('div');
            alert.className = 'rex-alert';
            alert.dataset.variant = success ? 'success' : 'danger';
            alert.setAttribute('role', success ? 'status' : 'alert');
            var strong = document.createElement('strong');
            strong.textContent = message;
            alert.appendChild(strong);
            [['Anbieter', details.provider], ['Modell', details.model], ['Schlüssel', details.env], ['Antwort', details.response], ['Fehler', details.error]].forEach(function (line) {
                if (!line[1]) {
                    return;
                }
                var row = document.createElement('div');
                row.textContent = line[0] + ': ';
                var code = document.createElement('code');
                code.textContent = line[1];
                row.appendChild(code);
                alert.appendChild(row);
            });
            result.innerHTML = '';
            result.appendChild(alert);
        }

        button.disabled = true;
        running.hidden = false;
        result.innerHTML = '';
        fetch(box.dataset.aiTestUrl, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                render(data.success, data.message, data.details || {});
            })
            .catch(function (error) {
                render(false, 'Anfrage fehlgeschlagen', {error: error.message});
            })
            .finally(function () {
                button.disabled = false;
                running.hidden = true;
            });
    });

    document.addEventListener('rex:ready', initForm);
    if (document.readyState !== 'loading') {
        initForm();
    } else {
        document.addEventListener('DOMContentLoaded', initForm);
    }
})();
