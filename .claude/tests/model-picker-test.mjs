/**
 * The model picker in assets/profiles.js.
 *
 * Runs the real asset file against a hand-rolled minimal DOM -- no npm, no jsdom, so
 * `node .claude/tests/model-picker-test.mjs` works on a plain checkout. The stub
 * implements only what profiles.js actually touches, and it copies the two browser
 * behaviours the logic depends on: assigning `select.value` is ignored unless an option
 * carries that value, and setting `innerHTML = ""` drops the options.
 *
 * The provider payload below is a fixture, not the real ProviderRegistry::formConfig()
 * output: this file is about the picker's behaviour, which must not start failing because
 * Symfony AI added a model. That the real payload has this shape is asserted in
 * provider-registry-test.php.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const assetPath = join(here, '..', '..', 'assets', 'profiles.js');

const PROVIDER_CONFIG = {
    openai: {
        fields: ['api_key', 'image_quality', 'image_style'],
        defaults: { text: 'gpt-4o', image_generation: 'dall-e-3', embedding: 'text-embedding-3-small' },
        models: {
            text: ['gpt-4o', 'gpt-4o-mini', 'gpt-5'],
            image_generation: ['dall-e-2', 'dall-e-3'],
            image_understanding: ['gpt-4o'],
            embedding: ['text-embedding-3-large', 'text-embedding-3-small'],
        },
    },
    ollama: {
        fields: ['api_key', 'base_url'],
        defaults: { text: 'llama3.2', image_understanding: 'llava' },
        models: { text: ['llama3.2', 'mistral'], image_generation: [], image_understanding: [], embedding: ['nomic-embed-text'] },
    },
    generic: {
        fields: ['api_key', 'base_url'],
        defaults: {},
        models: { text: [], image_generation: [], image_understanding: [], embedding: [] },
    },
};

// --- minimal DOM ------------------------------------------------------------

class El {
    constructor(tag, attrs = {}) {
        this.tagName = tag.toUpperCase();
        this.attributes = { ...attrs };
        this.children = [];
        this.parent = null;
        this.style = {};
        this.listeners = {};
        this.textContent = '';
        this.focused = false;
        this._value = '';
    }

    get className() {
        return this.attributes.class || '';
    }

    getAttribute(name) {
        return name in this.attributes ? this.attributes[name] : null;
    }

    appendChild(child) {
        child.parent = this;
        this.children.push(child);
        return child;
    }

    /** Only the "wipe everything" case is used. */
    set innerHTML(value) {
        if ('' !== value) throw new Error('the stub only supports innerHTML = ""');
        this.children = [];
    }

    get options() {
        return this.children.filter((c) => 'OPTION' === c.tagName);
    }

    get value() {
        if ('SELECT' === this.tagName) {
            return this.options.some((o) => o.value === this._value) ? this._value : '';
        }
        return this._value;
    }

    set value(v) {
        // A <select> ignores a value no option carries -- and reports "" instead. Getting
        // this wrong would make the test pass where the browser does not.
        if ('SELECT' === this.tagName) {
            this._value = this.options.some((o) => o.value === v) ? v : '';
            return;
        }
        this._value = v;
    }

    closest(selector) {
        const [tag, cls] = selector.split('.');
        for (let node = this; node; node = node.parent) {
            const tagOk = !tag || node.tagName === tag.toUpperCase();
            const clsOk = !cls || node.className.split(/\s+/).includes(cls);
            if (tagOk && clsOk) return node;
        }
        return null;
    }

    addEventListener(type, handler) {
        (this.listeners[type] = this.listeners[type] || []).push(handler);
    }

    dispatchEvent(event) {
        for (const handler of this.listeners[event.type] || []) handler.call(this, event);
    }

    focus() {
        this.focused = true;
    }
}

function buildDom({ withBootstrapSelect, model = '', type = 'text', provider = '' }) {
    const byId = new Map();
    const rows = new Map();

    const register = (el) => {
        if (el.attributes.id) byId.set(el.attributes.id, el);
        return el;
    };

    const typeSelect = register(new El('select', { id: 'ai-type-select' }));
    const providerSelect = register(new El('select', { id: 'ai-provider-select' }));
    for (const t of ['text', 'image_generation', 'image_understanding', 'embedding']) {
        typeSelect.appendChild(new El('option', {})).value = t;
    }
    for (const p of ['', 'openai', 'ollama', 'generic', 'unlisted']) {
        providerSelect.appendChild(new El('option', {})).value = p;
    }
    typeSelect.value = type;
    providerSelect.value = provider;

    // One rex_form row per field, so getFieldRow()'s closest("dl.rex-form-group") works.
    const inputs = new Map();
    for (const field of ['api_key', 'base_url', 'model', 'temperature', 'max_tokens', 'system_prompt',
        'image_size', 'image_quality', 'image_style', 'detail_level']) {
        const row = new El('dl', { class: 'rex-form-group' });
        const dd = row.appendChild(new El('dd'));
        const input = dd.appendChild(new El('input', { name: 'FORM[ai_profile][1][' + field + ']' }));
        rows.set(field, row);
        inputs.set(field, input);
    }
    inputs.get('model').value = model;

    // The model select is the field's prefix, so it lives in the same <dd>. With
    // bootstrap-select it gets wrapped, which is what the visibility toggle has to target.
    const modelSelect = register(new El('select', {
        id: 'ai-model-select',
        class: 'form-control selectpicker ai-model-select',
        'data-custom-label': '--- eigener Modellname ---',
    }));
    const modelDd = rows.get('model').children[0];
    if (withBootstrapSelect) {
        const wrapper = new El('div', { class: 'btn-group bootstrap-select form-control' });
        modelDd.children.unshift(wrapper);
        wrapper.parent = modelDd;
        wrapper.appendChild(modelSelect);
    } else {
        modelDd.children.unshift(modelSelect);
        modelSelect.parent = modelDd;
    }

    const configNode = register(new El('script', { id: 'ai-provider-config' }));
    configNode.textContent = JSON.stringify(PROVIDER_CONFIG);

    const document = {
        getElementById: (id) => byId.get(id) || null,
        querySelector: (selector) => {
            const match = /^\[name\$='\[(\w+)\]'\]$/.exec(selector);
            if (!match) throw new Error('the stub only supports [name$=\'[field]\'], got ' + selector);
            return inputs.get(match[1]) || null;
        },
        createElement: (tag) => new El(tag),
    };

    return { document, typeSelect, providerSelect, modelSelect, inputs, rows };
}

let refreshCount = 0;

function run(options) {
    const dom = buildDom(options);
    refreshCount = 0;

    let ready;
    globalThis.window = globalThis;
    globalThis.document = dom.document;
    globalThis.Event = class { constructor(type) { this.type = type; } };
    globalThis.Option = class extends El {
        constructor(text, value) {
            super('option');
            this.textContent = text;
            this._value = value;
        }
    };
    globalThis.$ = () => ({ on: (type, cb) => { if ('rex:ready' === type) ready = cb; } });
    globalThis.jQuery = options.withBootstrapSelect
        ? Object.assign(() => ({ selectpicker: (cmd) => { if ('refresh' === cmd) refreshCount++; } }), {
            fn: { selectpicker: true },
        })
        : undefined;

    // eslint-disable-next-line no-new-func
    new Function(readFileSync(assetPath, 'utf8'))();
    ready();

    return dom;
}

// --- assertions -------------------------------------------------------------

let passed = 0;
const failures = [];

function check(label, actual, expected) {
    const a = JSON.stringify(actual);
    const e = JSON.stringify(expected);
    if (a === e) {
        console.log('  OK    ' + label);
        passed++;
        return;
    }
    console.log('  FAIL  ' + label + ' — expected ' + e + ', got ' + a);
    failures.push(label);
}

function section(name) {
    console.log('\n--- ' + name + ' ---');
}

const visible = (el) => 'none' !== el.style.display;
const pickerBox = (dom) => dom.modelSelect.closest('div.bootstrap-select') || dom.modelSelect;

function state(dom) {
    return {
        pickerVisible: visible(pickerBox(dom)),
        options: dom.modelSelect.options.length,
        picked: dom.modelSelect.value,
        inputVisible: visible(dom.inputs.get('model')),
        stored: dom.inputs.get('model').value,
    };
}

function select(dom, type, provider) {
    dom.typeSelect.value = type;
    dom.typeSelect.dispatchEvent(new globalThis.Event('change'));
    dom.providerSelect.value = provider;
    dom.providerSelect.dispatchEvent(new globalThis.Event('change'));
}

console.log('\n=== Model picker (assets/profiles.js) ===');

section('Add mode, plain select');
{
    const dom = run({ withBootstrapSelect: false });

    check('no provider yet: nothing to pick, free text shown',
        state(dom), { pickerVisible: false, options: 0, picked: '', inputVisible: true, stored: '' });

    select(dom, 'text', 'openai');
    check('openai + text: catalog plus the custom entry, default picked',
        state(dom), { pickerVisible: true, options: 4, picked: 'gpt-4o', inputVisible: false, stored: 'gpt-4o' });

    dom.modelSelect.value = 'gpt-4o-mini';
    dom.modelSelect.dispatchEvent(new globalThis.Event('change'));
    check('picking another model writes it into the bound input',
        state(dom), { pickerVisible: true, options: 4, picked: 'gpt-4o-mini', inputVisible: false, stored: 'gpt-4o-mini' });

    dom.modelSelect.value = '__custom__';
    dom.modelSelect.dispatchEvent(new globalThis.Event('change'));
    check('the custom entry reveals the input and keeps the value',
        state(dom), { pickerVisible: true, options: 4, picked: '__custom__', inputVisible: true, stored: 'gpt-4o-mini' });
    check('and puts the cursor there', dom.inputs.get('model').focused, true);
}

section('Switching type or provider re-fits the model');
{
    const dom = run({ withBootstrapSelect: false });
    select(dom, 'text', 'openai');

    select(dom, 'embedding', 'openai');
    check('a text model does not survive the switch to embeddings',
        state(dom), { pickerVisible: true, options: 3, picked: 'text-embedding-3-small', inputVisible: false, stored: 'text-embedding-3-small' });

    // Deliberately picked, then the type changes: still replaced, because an embedding
    // model is not a text model no matter how deliberately it was chosen.
    dom.modelSelect.value = 'text-embedding-3-large';
    dom.modelSelect.dispatchEvent(new globalThis.Event('change'));
    select(dom, 'text', 'openai');
    check('a hand-picked model is replaced too when the type no longer fits it',
        state(dom), { pickerVisible: true, options: 4, picked: 'gpt-4o', inputVisible: false, stored: 'gpt-4o' });

    select(dom, 'image_understanding', 'ollama');
    check('a provider without a catalog for the type keeps the free text',
        state(dom), { pickerVisible: false, options: 0, picked: '', inputVisible: true, stored: 'llava' });

    // Switching to generic empties the name on purpose: the provider ships no default,
    // and a leftover llava would be a name from a different server.
    select(dom, 'text', 'generic');
    check('generic: no catalog at all, so no select and no pre-filled name',
        state(dom), { pickerVisible: false, options: 0, picked: '', inputVisible: true, stored: '' });

    dom.inputs.get('model').value = 'server-model-x';
    dom.inputs.get('model').dispatchEvent(new globalThis.Event('input'));
    select(dom, 'embedding', 'generic');
    check('a typed name survives a type switch while there is no catalog',
        state(dom), { pickerVisible: false, options: 0, picked: '', inputVisible: true, stored: 'server-model-x' });
}

section('Provider fields');
{
    const dom = run({ withBootstrapSelect: false });
    const fields = () => ({
        api_key: visible(dom.rows.get('api_key')),
        base_url: visible(dom.rows.get('base_url')),
        image_quality: visible(dom.rows.get('image_quality')),
    });

    select(dom, 'text', 'openai');
    check('openai: key, no base url', fields(), { api_key: true, base_url: false, image_quality: false });

    select(dom, 'image_generation', 'openai');
    check('openai + image generation: the DALL-E options appear', fields(), { api_key: true, base_url: false, image_quality: true });

    select(dom, 'text', 'ollama');
    check('ollama: key and base url', fields(), { api_key: true, base_url: true, image_quality: false });

    select(dom, 'text', 'unlisted');
    check('a provider missing from the payload shows both credential fields rather than hiding them',
        { api_key: fields().api_key, base_url: fields().base_url }, { api_key: true, base_url: true });
}

section('Edit mode: a stored name the catalog does not list');
{
    const dom = run({ withBootstrapSelect: false, model: 'my-own-model', type: 'text', provider: 'openai' });
    check('the stored value stays and the picker shows the custom entry',
        state(dom), { pickerVisible: true, options: 4, picked: '__custom__', inputVisible: true, stored: 'my-own-model' });

    const gone = run({ withBootstrapSelect: false, model: 'llama3.2-vision', type: 'image_understanding', provider: 'ollama' });
    check('same for a type without a catalog',
        state(gone), { pickerVisible: false, options: 0, picked: '', inputVisible: true, stored: 'llama3.2-vision' });
}

section('bootstrap-select integration');
{
    const dom = run({ withBootstrapSelect: true, model: '', type: 'text', provider: 'openai' });

    // be_style renders every .selectpicker once and does not watch the option list, so
    // without a refresh the box shows an empty list -- which is what made it look unlike
    // the provider box.
    check('the option list is announced to bootstrap-select', refreshCount > 0, true);

    const before = refreshCount;
    select(dom, 'embedding', 'openai');
    check('and again after the list is rebuilt', refreshCount > before, true);

    // bootstrap-select hides the original element itself, so toggling the select would
    // toggle something already invisible. The wrapper is the visible box.
    select(dom, 'text', 'generic');
    check('an empty catalog hides the wrapper, not the hidden original',
        { wrapper: visible(dom.modelSelect.closest('div.bootstrap-select')), select: dom.modelSelect.style.display },
        { wrapper: false, select: undefined });
}

console.log('\nModel picker: ' + passed + ' passed, ' + failures.length + ' failed');
for (const failure of failures) console.log('  - ' + failure);
process.exit(failures.length > 0 ? 1 : 0);
