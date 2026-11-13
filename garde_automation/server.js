const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');
const { TransferError, fillOfficialForm, locateFormPage, validatePayload } = require('./lib/transfer');

const HOST = '127.0.0.1';
const PORT = 3210;
const DEFAULT_OFFICIAL_FORM_URL = 'http://192.168.1.174/caisse-ctrl/decaissement/new-action/';
const MAX_BODY_BYTES = 16 * 1024;
const MAX_CLOCK_SKEW_SECONDS = 45;
const usedNonces = new Map();
let activeTransfer = false;
let contextPromise;

function getConfiguration() {
    const secret = process.env.OP_TRANSFER_SECRET;
    if (!secret || Buffer.byteLength(secret, 'utf8') < 32) {
        throw new Error('OP_TRANSFER_SECRET doit être configuré avec au moins 32 caractères.');
    }

    const officialUrl = process.env.OFFICIAL_FORM_URL || DEFAULT_OFFICIAL_FORM_URL;
    let target;
    try {
        target = new URL(officialUrl);
    } catch {
        throw new Error('OFFICIAL_FORM_URL doit contenir l’URL du formulaire officiel.');
    }
    if (!['http:', 'https:'].includes(target.protocol) || target.username || target.password) {
        throw new Error('OFFICIAL_FORM_URL doit être une URL HTTP(S) valide sans identifiants.');
    }

    const chromePath = process.env.CHROME_PATH
        || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    const profilePath = process.env.OP_TRANSFER_PROFILE
        || path.join(os.homedir(), 'AppData', 'Local', 'GardeOpTransfer', 'ChromeProfile');

    return { secret, target, chromePath, profilePath };
}

async function getBrowserContext(configuration) {
    if (!contextPromise) {
        contextPromise = (async () => {
            if (!fs.existsSync(configuration.chromePath)) {
                throw new Error('Google Chrome est introuvable; vérifiez CHROME_PATH.');
            }
            fs.mkdirSync(configuration.profilePath, { recursive: true });
            return chromium.launchPersistentContext(configuration.profilePath, {
                executablePath: configuration.chromePath,
                headless: false,
                acceptDownloads: false
            });
        })();
        contextPromise.catch(() => {
            contextPromise = undefined;
        });
    }

    const context = await contextPromise;
    let page = null;
    for (const candidate of context.pages()) {
        try {
            if (new URL(candidate.url()).origin === configuration.target.origin) {
                page = candidate;
                break;
            }
        } catch {
            continue;
        }
    }
    if (!page) {
        page = context.pages().find((candidate) => candidate.url() === 'about:blank')
            || await context.newPage();
    }
    if (page.url() !== configuration.target.href) {
        try {
            await page.goto(configuration.target.href, { waitUntil: 'domcontentloaded', timeout: 20000 });
        } catch {
            throw new TransferError(
                'Chrome est ouvert mais le lien direct du formulaire est inaccessible. Vérifiez la connexion au réseau interne.',
                503
            );
        }
    }
    return context;
}

function sendJson(response, statusCode, data) {
    const body = Buffer.from(JSON.stringify(data), 'utf8');
    response.writeHead(statusCode, {
        'Content-Type': 'application/json; charset=utf-8',
        'Content-Length': body.length,
        'Cache-Control': 'no-store',
        'X-Content-Type-Options': 'nosniff'
    });
    response.end(body);
}

function readRequestBody(request) {
    return new Promise((resolve, reject) => {
        const chunks = [];
        let length = 0;
        let oversized = false;
        request.on('data', (chunk) => {
            if (oversized) {
                return;
            }
            length += chunk.length;
            if (length > MAX_BODY_BYTES) {
                oversized = true;
                reject(new TransferError('Requête trop volumineuse.', 413));
                return;
            }
            chunks.push(chunk);
        });
        request.on('end', () => {
            if (!oversized) {
                resolve(Buffer.concat(chunks));
            }
        });
        request.on('error', reject);
    });
}

function verifySignature(request, body, secret) {
    const timestamp = request.headers['x-op-transfer-timestamp'];
    const nonce = request.headers['x-op-transfer-nonce'];
    const signature = request.headers['x-op-transfer-signature'];
    if (typeof timestamp !== 'string' || !/^\d{10}$/.test(timestamp)
        || typeof nonce !== 'string' || !/^[a-f0-9]{32}$/.test(nonce)
        || typeof signature !== 'string' || !/^[a-f0-9]{64}$/i.test(signature)) {
        throw new TransferError('Authentification technique manquante ou invalide.', 401);
    }

    const now = Math.floor(Date.now() / 1000);
    if (Math.abs(now - Number(timestamp)) > MAX_CLOCK_SKEW_SECONDS) {
        throw new TransferError('Requête expirée; relancez le transfert.', 401);
    }
    for (const [storedNonce, seenAt] of usedNonces) {
        if (now - seenAt > MAX_CLOCK_SKEW_SECONDS * 2) {
            usedNonces.delete(storedNonce);
        }
    }
    if (usedNonces.has(nonce)) {
        throw new TransferError('Requête déjà utilisée; relancez le transfert.', 401);
    }

    const expected = crypto.createHmac('sha256', secret)
        .update(`${timestamp}\n${nonce}\n`)
        .update(body)
        .digest();
    const received = Buffer.from(signature, 'hex');
    if (received.length !== expected.length || !crypto.timingSafeEqual(received, expected)) {
        throw new TransferError('Signature de la requête invalide.', 401);
    }
    usedNonces.set(nonce, now);
}

async function handleTransfer(request, response, configuration, dependencies = {}) {
    if (request.method !== 'POST' || request.url !== '/transfer') {
        sendJson(response, 404, { ok: false, error: 'Route locale introuvable.' });
        return;
    }
    if (!/^application\/json(?:\s*;|$)/i.test(request.headers['content-type'] || '')) {
        sendJson(response, 415, { ok: false, error: 'Le contenu doit être au format JSON.' });
        return;
    }

    let body;
    let ownsTransferLock = false;
    let fillStarted = false;
    try {
        body = await readRequestBody(request);
        verifySignature(request, body, configuration.secret);
        let payload;
        try {
            payload = JSON.parse(body.toString('utf8'));
        } catch {
            throw new TransferError('Corps JSON invalide.');
        }
        payload = validatePayload(payload);

        if (activeTransfer) {
            throw new TransferError('Une autre préparation Playwright est en cours.', 409);
        }
        activeTransfer = true;
        ownsTransferLock = true;
        const getContext = dependencies.getBrowserContext || getBrowserContext;
        const findPage = dependencies.locateFormPage || locateFormPage;
        const fill = dependencies.fillOfficialForm || fillOfficialForm;
        const context = await getContext(configuration);
        const page = await findPage(context, configuration.target);
        const result = await fill(page, payload, () => {
            fillStarted = true;
        });

        sendJson(response, 200, {
            ok: true,
            result: 'prepared',
            message: 'Formulaire prérempli. Vérifiez les champs dans Chrome; aucune validation officielle n’a été envoyée.',
            warnings: Array.isArray(result?.warnings) ? result.warnings : []
        });
    } catch (error) {
        const expected = error instanceof TransferError;
        const statusCode = expected ? error.statusCode : 500;
        const errorType = error instanceof Error ? error.name : 'Erreur inconnue';
        const errorCode = error && typeof error === 'object' && typeof error.code === 'string'
            ? `/${error.code}`
            : '';
        const message = expected
            ? error.message
            : `Erreur Playwright (${errorType}${errorCode}). Vérifiez le service local puis relancez la préparation.`;
        if (!expected) {
            console.error(`Erreur Playwright non détaillée : ${errorType}${errorCode}`);
        }
        sendJson(response, statusCode, {
            ok: false,
            result: fillStarted ? 'unknown' : 'not_started',
            error: message
        });
    } finally {
        if (ownsTransferLock) {
            activeTransfer = false;
        }
    }
}

async function startServer() {
    const configuration = getConfiguration();
    const server = http.createServer((request, response) => {
        handleTransfer(request, response, configuration).catch((error) => {
            const errorType = error instanceof Error ? error.name : 'Erreur inconnue';
            const errorCode = error && typeof error === 'object' && typeof error.code === 'string'
                ? `/${error.code}`
                : '';
            console.error(`Erreur HTTP locale non détaillée : ${errorType}${errorCode}`);
            if (!response.headersSent) {
                sendJson(response, 500, { ok: false, error: `Erreur interne du service local (${errorType}${errorCode}).` });
            }
        });
    });
    server.requestTimeout = 15000;
    server.headersTimeout = 10000;
    server.listen(PORT, HOST, () => {
        console.log(`Service de transfert local prêt sur ${HOST}:${PORT}.`);
        getBrowserContext(configuration).then(() => {
            console.log('Chrome est ouvert sur le formulaire. Connectez-vous manuellement si nécessaire.');
        }).catch((error) => {
            const errorType = error instanceof Error ? error.name : 'Erreur inconnue';
            const errorCode = error && typeof error === 'object' && typeof error.code === 'string'
                ? `/${error.code}`
                : '';
            console.error(`Ouverture du formulaire impossible (${errorType}${errorCode}).`);
        });
    });
    return server;
}

if (require.main === module) {
    startServer().catch((error) => {
        console.error(`Démarrage refusé : ${error.message}`);
        process.exitCode = 1;
    });
}

module.exports = {
    startServer,
    verifySignature,
    getConfiguration,
    getBrowserContext,
    handleTransfer
};
