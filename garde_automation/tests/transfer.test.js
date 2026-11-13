const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const http = require('node:http');
const { after, before, test } = require('node:test');
const { chromium } = require('playwright');
const {
    FIELD_IDS,
    TransferError,
    amountsMatch,
    fillOfficialForm,
    locateFormPage,
    normalizeAmount,
    validatePayload
} = require('../lib/transfer');
const { getConfiguration, handleTransfer, verifySignature } = require('../server');

const PC_LABEL = '4786188 - Autres dépenses à classer et à régulariser - Autres (Paiement garde permanant)';
const OBJECT_LABEL = 'indemnité de garde permanente';

const chromePath = process.env.CHROME_PATH
    || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const serviceSecret = 'fake-service-test-secret-is-not-an-official-credential';
const payload = {
    op_id: 17,
    fields: {
        tierCode: 'MAT-001',
        titulaire: 'Agent Test',
        exercice: '2026',
        mandat: '35/17',
        pcDepense: '4786188',
        montant: '122000.00',
        assignataire: '11001.100 / 11001.100 - TRESORERIE GENERALE D ANTSIRABE',
        objetDepense: OBJECT_LABEL,
        pieceJust: 'OP',
        dateEmission: '2026-03-05',
        soa: '00-21-T-223-00000',
        compte: '6241 Indemnités de mission intérieure'
    }
};

let browser;

function signedHeaders(body, nonce = crypto.randomBytes(16).toString('hex')) {
    const timestamp = String(Math.floor(Date.now() / 1000));
    const signature = crypto.createHmac('sha256', serviceSecret)
        .update(`${timestamp}\n${nonce}\n`)
        .update(body)
        .digest('hex');
    return {
        'content-type': 'application/json',
        'x-op-transfer-timestamp': timestamp,
        'x-op-transfer-nonce': nonce,
        'x-op-transfer-signature': signature
    };
}

async function createTestService(dependencies) {
    const configuration = {
        secret: serviceSecret,
        target: new URL('http://127.0.0.1:12345/form')
    };
    const server = http.createServer((request, response) =>
        handleTransfer(request, response, configuration, dependencies)
    );
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    return {
        server,
        address: server.address(),
        close: () => new Promise((resolve, reject) =>
            server.close((error) => error ? reject(error) : resolve())
        )
    };
}

function postToTestService(address, body, headers = signedHeaders(body)) {
    return new Promise((resolve, reject) => {
        const request = http.request({
            host: '127.0.0.1',
            port: address.port,
            path: '/transfer',
            method: 'POST',
            headers: {
                ...headers,
                'content-length': Buffer.byteLength(body)
            }
        }, (response) => {
            const chunks = [];
            response.on('data', (chunk) => chunks.push(chunk));
            response.on('end', () => resolve({
                statusCode: response.statusCode,
                body: JSON.parse(Buffer.concat(chunks).toString('utf8'))
            }));
        });
        request.on('error', reject);
        request.end(body);
    });
}

function fakeFormHtml({
    dateLabel = 'Date d’émission',
    includePcOption = true,
    dateType = 'date',
    formatSelect2Label = false,
    pcLabel = PC_LABEL,
    accountTag = 'input',
    accountType = 'text',
    prefilled = false,
    prefilledAssignataire = false,
    assignataireDisplayMismatch = false,
    duplicatePcOption = false,
    select2ClearControl = false
} = {}) {
    const controls = Object.entries(FIELD_IDS).map(([key, id]) => {
        if (key === 'pcDepense' || key === 'assignataire') {
            const value = key === 'pcDepense' ? '4786188' : '11001.100';
            const label = key === 'pcDepense' ? pcLabel : '11001.100 / 11001.100 - TRESORERIE GENERALE D ANTSIRABE';
            const includeOption = key === 'pcDepense' ? includePcOption : true;
            const isSelected = prefilled || (key === 'assignataire' && prefilledAssignataire);
            const selected = isSelected ? ' selected' : '';
            const option = includeOption ? `<option value="${value}"${selected}>${label}</option>` : '';
            const duplicate = key === 'pcDepense' && duplicatePcOption
                ? `<option value="${value}">${label}</option>`
                : '';
            const initialText = isSelected ? label : 'Choisir';
            const displayText = assignataireDisplayMismatch && key === 'assignataire'
                ? 'Libellé non reconnu'
                : formatSelect2Label && isSelected
                    ? `${label} (${value})`
                    : initialText;
            const title = isSelected && !(assignataireDisplayMismatch && key === 'assignataire') ? label : '';
            return `<label for="${id}">${key}</label><select id="${id}"><option value="">Choisir</option>${option}${duplicate}</select><span class="select2"><span class="select2-selection__rendered" title="${title}">${displayText}</span></span>`;
        }
        if (key === 'compte' && accountTag === 'select') {
            return `<label for="${id}">Compte</label><select id="${id}"><option value="">Choisir</option><option value="${payload.fields.compte}">Compte du snapshot</option></select>`;
        }
        const type = key === 'dateEmission' ? dateType : 'text';
        const label = key === 'dateEmission' ? dateLabel : key === 'compte' ? 'Compte' : key;
        const value = prefilled ? ` value="${payload.fields[key]}"` : '';
        const accountInputType = key === 'compte' ? accountType : type;
        return `<label for="${id}">${label}</label><input id="${id}" type="${accountInputType}"${value}>`;
    }).join('');

    return `<form id="fake-form">${controls}
        <button id="save" type="submit">Enregistrer</button>
    </form>
    <script>
        window.submitted = false;
        window.mutationCount = 0;
        document.querySelector('#fake-form').addEventListener('submit', event => {
            event.preventDefault();
            window.submitted = true;
        });
        document.querySelectorAll('#fake-form input').forEach(input => {
            input.addEventListener('input', () => { window.mutationCount += 1; });
        });
        for (const id of ['${FIELD_IDS.pcDepense}', '${FIELD_IDS.assignataire}']) {
            const select = document.getElementById(id);
            select.addEventListener('change', () => {
                const rendered = select.nextElementSibling.querySelector('.select2-selection__rendered');
                if (${select2ClearControl} && id === '${FIELD_IDS.pcDepense}') {
                    rendered.textContent = select.options[select.selectedIndex].textContent + ' ×';
                    rendered.removeAttribute('title');
                } else if (${assignataireDisplayMismatch} && id === '${FIELD_IDS.assignataire}') {
                    rendered.textContent = 'Libellé non reconnu';
                    rendered.title = 'Libellé non reconnu';
                } else {
                    rendered.textContent =
                        ${formatSelect2Label ? '`${select.options[select.selectedIndex].textContent} (${select.value})`' : 'select.options[select.selectedIndex].textContent'};
                    rendered.title = select.options[select.selectedIndex].textContent;
                }
                window.mutationCount += 1;
            });
        }
    </script>`;
}

before(async () => {
    browser = await chromium.launch({ executablePath: chromePath, headless: true });
});

after(async () => {
    await browser?.close();
});

test('fills and verifies the demonstration form without submitting it', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml());

    const result = await fillOfficialForm(page, payload);
    assert.equal(result.op_id, 17);
    assert.equal(result.verified, 12);
    for (const [key, id] of Object.entries(FIELD_IDS)) {
        const actual = await page.locator(`#${id}`).inputValue();
        if (key === 'montant') {
            assert.ok(amountsMatch(actual, payload.fields[key]));
        } else if (key === 'assignataire') {
            assert.equal(actual, '11001.100');
        } else {
            assert.equal(actual, payload.fields[key]);
        }
    }
    assert.equal(await page.evaluate(() => window.submitted), false);
    await page.close();
});

test('opens the configured direct URL in the controlled Chrome context', async () => {
    const formServer = http.createServer((request, response) => {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        response.end(fakeFormHtml());
    });
    await new Promise((resolve) => formServer.listen(0, '127.0.0.1', resolve));

    const context = await browser.newContext();
    try {
        const page = await context.newPage();
        const address = formServer.address();
        const target = new URL(`http://127.0.0.1:${address.port}/caisse-ctrl/decaissement/new-action/`);
        const locatedPage = await locateFormPage(context, target);

        assert.equal(locatedPage, page);
        assert.equal(page.url(), target.href);
        assert.equal(await page.locator(`#${FIELD_IDS.tierCode}`).count(), 1);
    } finally {
        await context.close();
        await new Promise((resolve, reject) => formServer.close((error) => error ? reject(error) : resolve()));
    }
});

test('refuses a Select2 option missing from the live form before changing any field', async () => {
    const page = await browser.newPage();
    page.setDefaultTimeout(1500);
    await page.setContent(fakeFormHtml({ includePcOption: false }));

    await assert.rejects(
        fillOfficialForm(page, payload),
        /Aucune option unique/
    );
    assert.equal(await page.locator(`#${FIELD_IDS.tierCode}`).inputValue(), '');
    assert.equal(await page.evaluate(() => window.mutationCount), 0);
    await page.close();
});

test('leaves an ambiguously labeled date untouched and prepares other identified fields', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml({ dateLabel: 'Date inconnue' }));

    const result = await fillOfficialForm(page, payload);
    assert.equal(await page.locator(`#${FIELD_IDS.dateEmission}`).inputValue(), '');
    assert.equal(await page.locator(`#${FIELD_IDS.titulaire}`).inputValue(), payload.fields.titulaire);
    assert.ok(result.warnings.some((warning) => warning.includes('Date laissée intacte')));
    assert.equal(result.verified, 11);
    await page.close();
});

test('fills a text date field with the source date in ISO format', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml({ dateType: 'text' }));

    const result = await fillOfficialForm(page, payload);
    assert.equal(result.verified, 12);
    assert.equal(await page.locator(`#${FIELD_IDS.dateEmission}`).inputValue(), '2026-03-05');
    assert.equal(await page.evaluate(() => window.submitted), false);
    await page.close();
});

test('accepts Select2 labels that include additional option details', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml({ formatSelect2Label: true, prefilled: true }));

    const result = await fillOfficialForm(page, payload);
    assert.equal(result.verified, 12);
    assert.equal(await page.locator(`#${FIELD_IDS.pcDepense}`).inputValue(), '4786188');
    assert.equal(await page.locator(`#${FIELD_IDS.pcDepense}`).locator('option:checked').textContent(), PC_LABEL);
    await page.close();
});

test('verifies a selected PC option when Select2 appends visible control text', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml({ select2ClearControl: true }));

    const result = await fillOfficialForm(page, payload);
    assert.ok(result.verified > 0);
    assert.equal(await page.locator(`#${FIELD_IDS.pcDepense}`).inputValue(), '4786188');
    assert.equal(
        await page.locator(`#${FIELD_IDS.pcDepense}`).locator('option:checked').textContent(),
        PC_LABEL
    );
    assert.match(
        await page.locator(`#${FIELD_IDS.pcDepense}`).evaluate((select) =>
            select.nextElementSibling.querySelector('.select2-selection__rendered').textContent
        ),
        /4786188 - Autres dépenses à classer et à régulariser - Autres \(Paiement garde permanant\) ×$/
    );
    await page.close();
});

test('continues filling remaining fields when the assignataire Select2 display mismatches after selection', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml({ assignataireDisplayMismatch: true }));

    const result = await fillOfficialForm(page, payload);
    assert.equal(await page.locator(`#${FIELD_IDS.assignataire}`).inputValue(), '11001.100');
    assert.equal(await page.locator(`#${FIELD_IDS.objetDepense}`).inputValue(), OBJECT_LABEL);
    assert.equal(await page.locator(`#${FIELD_IDS.pieceJust}`).inputValue(), 'OP');
    assert.equal(await page.locator(`#${FIELD_IDS.soa}`).inputValue(), payload.fields.soa);
    assert.ok(result.warnings.some((warning) => warning.includes('Select2 assignataire')));
    assert.equal(result.verified, 11);
    assert.equal(await page.evaluate(() => window.submitted), false);
    await page.close();
});

test('continues filling when a preselected assignataire has a mismatched Select2 display', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml({
        prefilledAssignataire: true,
        assignataireDisplayMismatch: true
    }));

    const result = await fillOfficialForm(page, payload);
    assert.equal(await page.locator(`#${FIELD_IDS.assignataire}`).inputValue(), '11001.100');
    assert.equal(await page.locator(`#${FIELD_IDS.objetDepense}`).inputValue(), OBJECT_LABEL);
    assert.ok(result.warnings.some((warning) => warning.includes('Select2 assignataire')));
    assert.equal(result.verified, 11);
    assert.equal(await page.evaluate(() => window.submitted), false);
    await page.close();
});

test('rejects a PC spending option with an unexpected live label before changing fields', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml({ pcLabel: 'Libellé différent' }));
    await assert.rejects(fillOfficialForm(page, payload), /libellé.*PC dépenses/i);
    assert.equal(await page.evaluate(() => window.mutationCount), 0);
    await page.close();
});

test('rejects duplicate Select2 option values as ambiguous before changing fields', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml({ duplicatePcOption: true }));
    await assert.rejects(fillOfficialForm(page, payload), /sélection ambiguë/);
    assert.equal(await page.evaluate(() => window.mutationCount), 0);
    await page.close();
});

test('does not rewrite fields already prefilled with the exact snapshot values', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml({ prefilled: true }));
    let fillStarted = false;

    const result = await fillOfficialForm(page, payload, () => { fillStarted = true; });
    assert.equal(fillStarted, false);
    assert.equal(await page.evaluate(() => window.mutationCount), 0);
    assert.equal(result.verified, 12);
    await page.close();
});

test('calls onFillStarted immediately before the first actual field change', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml());
    let fillStarted = false;
    await page.evaluate((id) => {
        document.getElementById(id).addEventListener('input', () => {
            window.fillWasStartedAtFirstInput = window.fillCallbackObserved === true;
        });
    }, FIELD_IDS.tierCode);

    await fillOfficialForm(page, payload, () => {
        fillStarted = true;
        return page.evaluate(() => { window.fillCallbackObserved = true; });
    });

    assert.equal(fillStarted, true);
    assert.equal(await page.evaluate(() => window.fillWasStartedAtFirstInput), true);
    await page.close();
});

test('fills an account text field with the snapshot value', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml());
    const result = await fillOfficialForm(page, payload);
    assert.equal(await page.locator(`#${FIELD_IDS.compte}`).inputValue(), payload.fields.compte);
    assert.equal(result.verified, 12);
    await page.close();
});

test('selects a unique account option only when its value matches the snapshot', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml({ accountTag: 'select' }));
    const result = await fillOfficialForm(page, payload);
    assert.equal(await page.locator(`#${FIELD_IDS.compte}`).inputValue(), payload.fields.compte);
    assert.equal(await page.locator(`#${FIELD_IDS.compte}`).locator('option:checked').textContent(), 'Compte du snapshot');
    assert.ok(!result.warnings.some((warning) => warning.startsWith('Compte laissé intact')));
    await page.close();
});

test('rejects an unexpected account control type before changing fields', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml({ accountType: 'number' }));
    await assert.rejects(fillOfficialForm(page, payload), /champ Compte n’est pas un texte libre/);
    assert.equal(await page.evaluate(() => window.mutationCount), 0);
    await page.close();
});

test('reports an error after the first actual field modification as a partial fill', async () => {
    const page = await browser.newPage();
    page.setDefaultTimeout(1500);
    await page.setContent(fakeFormHtml());
    let fillStarted = false;
    await page.evaluate((ids) => {
        document.getElementById(ids.tierCode).addEventListener('input', () => {
            document.getElementById(ids.mandat).readOnly = true;
        });
    }, FIELD_IDS);

    await assert.rejects(
        fillOfficialForm(page, payload, () => { fillStarted = true; }),
        /read-only|readonly|editable/i
    );
    assert.equal(fillStarted, true);
    assert.equal(await page.locator(`#${FIELD_IDS.tierCode}`).inputValue(), payload.fields.tierCode);
    await page.close();
});

test('rejects malformed or unsafe payloads', () => {
    assert.throws(() => validatePayload({ ...payload, fields: { ...payload.fields, montant: '-1' } }), /NET À PAYER invalide/);
    assert.throws(() => validatePayload({ ...payload, fields: { ...payload.fields, dateEmission: '2026-02-31' } }), /Date d’émission invalide/);
    assert.throws(() => validatePayload({ ...payload, fields: { ...payload.fields, objetDepense: 'Autre objet' } }), /Objet de dépense invalide/);
});

test('authenticates signed requests and rejects replay or a changed body', () => {
    const secret = 'fixture-secret-never-used-outside-tests-123456';
    const body = Buffer.from('{"op_id":17}');
    const timestamp = String(Math.floor(Date.now() / 1000));
    const nonce = crypto.randomBytes(16).toString('hex');
    const signature = crypto.createHmac('sha256', secret)
        .update(`${timestamp}\n${nonce}\n`)
        .update(body)
        .digest('hex');
    const request = {
        headers: {
            'x-op-transfer-timestamp': timestamp,
            'x-op-transfer-nonce': nonce,
            'x-op-transfer-signature': signature
        }
    };

    assert.doesNotThrow(() => verifySignature(request, body, secret));
    assert.throws(() => verifySignature(request, body, secret), /déjà utilisée/);

    const changedNonce = crypto.randomBytes(16).toString('hex');
    const changedRequest = {
        headers: { ...request.headers, 'x-op-transfer-nonce': changedNonce }
    };
    assert.throws(() => verifySignature(changedRequest, Buffer.from('{"op_id":18}'), secret), /Signature/);
});

test('rejects an invalid HMAC before any browser interaction', async () => {
    let browserCalls = 0;
    const service = await createTestService({
        getBrowserContext: async () => { browserCalls += 1; return {}; },
        locateFormPage: async () => ({}),
        fillOfficialForm: async () => ({ warnings: [] })
    });
    try {
        const body = JSON.stringify(payload);
        const headers = signedHeaders(body);
        headers['x-op-transfer-signature'] = '0'.repeat(64);
        const response = await postToTestService(service.address, body, headers);
        assert.equal(response.statusCode, 401);
        assert.equal(response.body.result, 'not_started');
        assert.equal(browserCalls, 0);
    } finally {
        await service.close();
    }
});

test('classifies a pre-fill form lookup failure as not_started', async () => {
    let fillCalls = 0;
    const service = await createTestService({
        getBrowserContext: async () => ({}),
        locateFormPage: async () => { throw new TransferError('Formulaire de test absent.', 409); },
        fillOfficialForm: async () => { fillCalls += 1; return { warnings: [] }; }
    });
    try {
        const response = await postToTestService(service.address, JSON.stringify(payload));
        assert.equal(response.statusCode, 409);
        assert.equal(response.body.result, 'not_started');
        assert.equal(fillCalls, 0);
    } finally {
        await service.close();
    }
});

test('returns warnings when a form field was deliberately left untouched', async () => {
    const warning = 'Compte laissé intact : format attendu non établi.';
    const service = await createTestService({
        getBrowserContext: async () => ({}),
        locateFormPage: async () => ({}),
        fillOfficialForm: async () => ({ warnings: [warning] })
    });
    try {
        const response = await postToTestService(service.address, JSON.stringify(payload));
        assert.equal(response.statusCode, 200);
        assert.deepEqual(response.body.warnings, [warning]);
    } finally {
        await service.close();
    }
});

test('classifies a failure after onFillStarted as unknown', async () => {
    const service = await createTestService({
        getBrowserContext: async () => ({}),
        locateFormPage: async () => ({}),
        fillOfficialForm: async (_page, _payload, onFillStarted) => {
            onFillStarted();
            throw new TransferError('Interruption simulée pendant la préparation.', 500);
        }
    });
    try {
        const response = await postToTestService(service.address, JSON.stringify(payload));
        assert.equal(response.statusCode, 500);
        assert.equal(response.body.result, 'unknown');
    } finally {
        await service.close();
    }
});

test('rejects a repeated nonce at the HTTP boundary', async () => {
    const service = await createTestService({
        getBrowserContext: async () => ({}),
        locateFormPage: async () => ({}),
        fillOfficialForm: async () => ({ warnings: [] })
    });
    try {
        const body = JSON.stringify(payload);
        const headers = signedHeaders(body);
        const first = await postToTestService(service.address, body, headers);
        const replay = await postToTestService(service.address, body, headers);
        assert.equal(first.statusCode, 200);
        assert.equal(replay.statusCode, 401);
        assert.equal(replay.body.result, 'not_started');
    } finally {
        await service.close();
    }
});

test('rejects a simultaneous second preparation while the first is running', async () => {
    let signalStarted;
    let releaseFirst;
    const started = new Promise((resolve) => { signalStarted = resolve; });
    const firstMayFinish = new Promise((resolve) => { releaseFirst = resolve; });
    const service = await createTestService({
        getBrowserContext: async () => ({}),
        locateFormPage: async () => ({}),
        fillOfficialForm: async (_page, _payload, onFillStarted) => {
            onFillStarted();
            signalStarted();
            await firstMayFinish;
            return { warnings: [] };
        }
    });
    try {
        const firstPromise = postToTestService(service.address, JSON.stringify(payload));
        await started;
        const second = await postToTestService(service.address, JSON.stringify(payload));
        assert.equal(second.statusCode, 409);
        assert.equal(second.body.result, 'not_started');
        releaseFirst();
        const first = await firstPromise;
        assert.equal(first.statusCode, 200);
        assert.equal(first.body.result, 'prepared');
    } finally {
        releaseFirst();
        await service.close();
    }
});

test('rejects oversized payloads before any form interaction', async () => {
    let browserCalls = 0;
    const service = await createTestService({
        getBrowserContext: async () => { browserCalls += 1; return {}; },
        locateFormPage: async () => ({}),
        fillOfficialForm: async () => ({ warnings: [] })
    });
    try {
        const body = JSON.stringify({ ...payload, extra: 'x'.repeat(17 * 1024) });
        const response = await postToTestService(service.address, body);
        assert.equal(response.statusCode, 413);
        assert.equal(response.body.result, 'not_started');
        assert.equal(browserCalls, 0);
    } finally {
        await service.close();
    }
});

test('uses the supplied portal form URL as the default direct destination', () => {
    const previousSecret = process.env.OP_TRANSFER_SECRET;
    const previousUrl = process.env.OFFICIAL_FORM_URL;
    try {
        process.env.OP_TRANSFER_SECRET = 'fixture-secret-never-used-outside-tests-123456';
        delete process.env.OFFICIAL_FORM_URL;

        assert.equal(
            getConfiguration().target.href,
            'http://192.168.1.174/caisse-ctrl/decaissement/new-action/'
        );
    } finally {
        if (previousSecret === undefined) {
            delete process.env.OP_TRANSFER_SECRET;
        } else {
            process.env.OP_TRANSFER_SECRET = previousSecret;
        }
        if (previousUrl === undefined) {
            delete process.env.OFFICIAL_FORM_URL;
        } else {
            process.env.OFFICIAL_FORM_URL = previousUrl;
        }
    }
});

test('amountsMatch correctly compares amounts with thousands separators and decimals', () => {
    // Normal space
    assert.ok(amountsMatch('30 000', '30000.00'));
    assert.ok(amountsMatch('30 000', '30000'));
    assert.ok(amountsMatch('122 000', '122000.00'));
    // Non-breaking space (\u00A0) and narrow non-breaking space (\u202F)
    assert.ok(amountsMatch('30\u00A0000', '30000.00'));
    assert.ok(amountsMatch('30\u202F000', '30000.00'));
    // Comma decimal separator
    assert.ok(amountsMatch('30 000,00', '30000.00'));
    assert.ok(amountsMatch('122 000,50', '122000.50'));
    // Plain without separator
    assert.ok(amountsMatch('30000', '30000.00'));
    assert.ok(amountsMatch('30000.00', '30000.00'));
    // Currency suffixes
    assert.ok(amountsMatch('30 000 Ar', '30000.00'));
    assert.ok(amountsMatch('30 000 Ariary', '30000.00'));
    // Mismatches
    assert.equal(amountsMatch('3', '30000.00'), false);
    assert.equal(amountsMatch('30', '30000.00'), false);
    assert.equal(amountsMatch('300', '30000.00'), false);
    assert.equal(amountsMatch('3000', '30000.00'), false);
    assert.equal(amountsMatch('35 000', '30000.00'), false);
    assert.equal(amountsMatch('', '30000.00'), false);
    assert.equal(amountsMatch('invalid', '30000.00'), false);
});

test('fills and verifies amount field when an input mask formats with thousands separators', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml());
    // Attach input mask that formats with space thousands separator
    await page.evaluate((id) => {
        const input = document.getElementById(id);
        input.addEventListener('input', () => {
            const raw = input.value.replace(/\D/g, '');
            if (raw) {
                input.value = raw.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
            }
        });
    }, FIELD_IDS.montant);

    const result = await fillOfficialForm(page, payload);
    assert.equal(result.op_id, 17);
    const actual = await page.locator(`#${FIELD_IDS.montant}`).inputValue();
    assert.equal(actual, '122 000');
    assert.ok(amountsMatch(actual, payload.fields.montant));
    await page.close();
});

test('does not rewrite amount field already prefilled with thousands separator', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml({ prefilled: true }));
    await page.locator(`#${FIELD_IDS.montant}`).evaluate((input) => {
        input.value = '122 000';
    });
    let fillStarted = false;
    const result = await fillOfficialForm(page, payload, () => { fillStarted = true; });
    assert.equal(fillStarted, false);
    assert.equal(await page.locator(`#${FIELD_IDS.montant}`).inputValue(), '122 000');
    assert.equal(result.verified, 12);
    await page.close();
});

test('works with official Inputmask instance (setValue, unmaskedvalue)', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml());
    await page.evaluate((id) => {
        const input = document.getElementById(id);
        input.inputmask = {
            setValue(val) {
                // Simule le comportement du masque Inputmask officiel qui reçoit l'entier
                input.value = val.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
            },
            unmaskedvalue() {
                return input.value.replace(/\s/g, '');
            }
        };
    }, FIELD_IDS.montant);

    const result = await fillOfficialForm(page, payload);
    assert.equal(result.op_id, 17);
    const actual = await page.locator(`#${FIELD_IDS.montant}`).inputValue();
    assert.equal(actual, '122 000');
    assert.ok(amountsMatch(actual, payload.fields.montant));
    await page.close();
});

test('matches assignataire option when snapshot contains code and full label', async () => {
    const page = await browser.newPage();
    await page.setContent(fakeFormHtml());
    await page.evaluate((id) => {
        const select = document.getElementById(id);
        select.innerHTML = '<option value="">Choisir</option><option value="11001.100">11001.100 / 11001.100 - TRESORERIE GENERALE D ANTSIRABE</option>';
    }, FIELD_IDS.assignataire);

    const customPayload = {
        ...payload,
        fields: {
            ...payload.fields,
            assignataire: '11001.100 / 11001.100 - TRESORERIE GENERALE D ANTSIRABE'
        }
    };
    const result = await fillOfficialForm(page, customPayload);
    assert.equal(result.op_id, 17);
    assert.equal(await page.locator(`#${FIELD_IDS.assignataire}`).inputValue(), '11001.100');
    await page.close();
});
