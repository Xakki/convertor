#!/usr/bin/env node
'use strict';

const fs = require('fs');
const vm = require('vm');

const scriptPath = process.argv[2];
if (!scriptPath) throw new Error('template path is required');

const source = fs.readFileSync(scriptPath, 'utf8')
    .replace(/<script[^>]*>|<\/script>/g, '')
    .replace(/\{\{[\s\S]*?\}\}/g, 'fixture');
const runtime = { console, TextEncoder, URL, setTimeout, clearTimeout };
vm.createContext(runtime);
let converterApp;
const realmValue = (value) => vm.runInContext(`JSON.parse(${JSON.stringify(JSON.stringify(value))})`, runtime);

const localStorage = new Map();
runtime.localStorage = {
    getItem: (key) => localStorage.has(key) ? localStorage.get(key) : null,
    setItem: (key, value) => localStorage.set(key, String(value)),
    removeItem: (key) => localStorage.delete(key),
};
runtime.window = {
    sharedAuthRefresh: async () => ({ ok: false, token: null }),
    location: { href: '' },
};
runtime.location = { search: '', pathname: '/' };
runtime.history = { replaceState: () => {} };
runtime.document = { getElementById: () => null };
runtime.filePreviewViewerType = () => null;
runtime.filePreviewIconKey = () => null;
runtime.billingTopUpMixin = () => ({});
runtime.filePreviewMixin = () => ({});
runtime.BILLING_I18N = { insufficientBalance: 'insufficient balance' };
runtime.FormData = class {
    constructor() { this.entriesList = []; }
    append(key, value) { this.entriesList.push([key, String(value)]); }
    entries() { return this.entriesList[Symbol.iterator](); }
};

runtime.I18N = {
    errors: { formatsLoadFailed: 'formats failed', sessionExpired: 'session expired', uploadNetwork: 'upload network' },
    status: {}, units: { bytes: 'bytes', kb: 'KB', mb: 'MB' }, showcase: { category: {} },
};
converterApp = vm.runInContext(`${source}\nconverterApp`, runtime);

function fixtureApp(overrides = {}) {
    const app = converterApp();
    app.formats = realmValue([{ from: 'jpg', to: 'png', category: 'image', isAi: false, ocrCapable: true, settingsProfile: 'image' }]);
    app.settingsVersion = 'v1';
    app.settingsProfiles = realmValue({
        image: { id: 'image', label: 'Image', fields: [
            { key: 'quality', type: 'range', label: 'Quality', editable: true, min: 1, max: 100, step: 1, default: 80 },
            { key: 'alpha', type: 'boolean', label: 'Alpha', editable: true, default: false },
            { key: 'preset', type: 'select', label: 'Preset', editable: true, options: [
                { value: 'fast', label: 'Fast', editable: true, minPlan: 'guest' },
                { value: 'locked', label: 'Locked', editable: false, minPlan: 'pro' },
            ] },
        ] },
    });
    app.fromFormat = 'jpg';
    app.toFormat = 'png';
    Object.assign(app, realmValue(overrides));
    return app;
}

async function runScenario(name) {
    if (name === 'normalized-submit') {
        const app = fixtureApp({ settingsValues: { quality: '90', alpha: true, preset: 'locked', unknown: 'drop' }, inputMode: 'text', text: 'hello' });
        const requests = [];
        runtime.fetch = async (url, opts) => { requests.push({ url, opts }); return { status: 202, ok: true, json: async () => ({ conversion_id: 'c1', status: 'pending' }) }; };
        app.resetJob = () => { app.job = { id: null, status: null, error: null }; };
        app.startPolling = () => {};
        app.persistSettings = () => {};
        await app.submit();
        const entries = [...requests[0].opts.body.entries()];
        const actual = Object.fromEntries(entries);
        if (actual['options[quality]'] !== '90' || actual['options[alpha]'] !== 'true') throw new Error('valid settings were not normalized');
        if ('options[preset]' in actual || 'options[unknown]' in actual) throw new Error('forbidden settings leaked');
        if (actual.to_format !== 'png' || actual.source_format !== 'jpg') throw new Error('target/source fields missing');
        return { entries: actual };
    }
    if (name === 'ocr-no-options') {
        const app = fixtureApp({ ocr: true, settingsValues: { quality: 90, alpha: true } });
        const values = app.normalizedSettings();
        if (Object.keys(values).length !== 0) throw new Error('OCR emitted settings');
        return { values };
    }
    if (name === 'catalog-fail-closed') {
        const app = fixtureApp();
        const valid = realmValue({ formats: app.formats, settings: { version: 'v1', profiles: app.settingsProfiles } });
        if (!app.isValidFormatsPayload(valid)) throw new Error('valid catalog rejected');
        const malformed = realmValue({ formats: [{ ...app.formats[0], settingsProfile: 'missing' }], settings: valid.settings });
        if (app.isValidFormatsPayload(malformed)) throw new Error('malformed catalog accepted');
        runtime.fetch = async () => ({ ok: true, status: 200, json: async () => malformed });
        await app.loadFormats();
        if (app.formats.length !== 0 || Object.keys(app.settingsProfiles).length !== 0 || !app.settingsError) throw new Error('loadFormats did not fail closed');
        return { formats: app.formats, settingsError: app.settingsError };
    }
    if (name === 'persist-target-version') {
        const app = fixtureApp({ settingsValues: { quality: 77 } });
        app.persistSettings();
        if (localStorage.get('convertor:settings:png') !== JSON.stringify({ version: 'v1', values: { quality: 77 } })) throw new Error('state was not persisted by target/version');
        app.toFormat = 'webp';
        app.settingsValues = {};
        app.loadSettingsState();
        if (Object.keys(app.settingsValues).length !== 0) throw new Error('state leaked across targets');
        return { keys: [...localStorage.keys()] };
    }
    if (name === 'auth-retry') {
        const app = fixtureApp();
        app.auth.token = 'old';
        let calls = 0;
        runtime.window.sharedAuthRefresh = async () => ({ ok: true, token: 'new' });
        runtime.fetch = async (url, opts) => { calls += 1; return calls === 1 ? { status: 401, ok: false } : { status: 200, ok: true, json: async () => realmValue({ formats: app.formats, settings: { version: 'v1', profiles: app.settingsProfiles } }) }; };
        const response = await app.authFetch('/api/v1/formats');
        if (calls !== 2 || response.status !== 200 || app.auth.token !== 'new') throw new Error('authenticated retry was not bounded');
        app.auth.token = 'old'; calls = 0; runtime.window.sharedAuthRefresh = async () => ({ ok: false, token: null });
        app.clearAuth = () => { app.auth.token = null; }; app.resetJob = () => {};
        const failed = await app.authFetch('/api/v1/formats');
        if (calls !== 1 || failed.status !== 401 || app.auth.token !== null || !app.authError) throw new Error('failed auth did not fail closed');
        return { successfulCalls: 2, failedCalls: calls };
    }
    throw new Error(`unknown scenario: ${name}`);
}

runScenario(process.argv[3]).then((result) => process.stdout.write(JSON.stringify(result) + '\n')).catch((error) => { process.stderr.write(error.stack + '\n'); process.exit(1); });
