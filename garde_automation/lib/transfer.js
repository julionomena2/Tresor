const FIELD_IDS = Object.freeze({
    tierCode: 'apayer_standard_tierCode',
    titulaire: 'apayer_standard_titulaire',
    exercice: 'apayer_standard_apayExercice',
    mandat: 'apayer_standard_apayMandat',
    pcDepense: 'apayer_standard_apayPcDepense',
    montant: 'apayer_standard_apayMontant',
    assignataire: 'apayer_standard_apayAssignataire',
    objetDepense: 'apayer_standard_apayObjetDepense',
    pieceJust: 'apayer_standard_apayPieceJust',
    dateEmission: 'apayer_standard_apayDateVisa',
    soa: 'apayer_standard_apaySoa',
    compte: 'apayer_standard_apayCompte'
});

const FIELD_KEYS = Object.freeze(Object.keys(FIELD_IDS));
const MAX_FIELD_LENGTH = 500;
const PC_DEPENSE_VALUE = '4786188';
const PC_DEPENSE_LABEL = 'Autres dépenses à classer et à régulariser - Autres (Paiement garde permanant)';
const ASSIGNE_VALUE = '11001.100 / 11001.100 - TRESORERIE GENERALE D ANTSIRABE';
const ASSIGNATAIRE_DISPLAY_WARNING = 'La valeur visible du composant Select2 assignataire ne correspond pas à la sélection; le remplissage continue, mais ce champ doit être vérifié manuellement.';
const OBJET_DEPENSE = 'indemnité de garde permanente';

class TransferError extends Error {
    constructor(message, statusCode = 422) {
        super(message);
        this.name = 'TransferError';
        this.statusCode = statusCode;
    }
}

function validatePayload(payload) {
    if (!payload || !Number.isSafeInteger(payload.op_id) || payload.op_id < 1) {
        throw new TransferError('Identifiant OP absent ou invalide.');
    }
    if (!payload.fields || typeof payload.fields !== 'object' || Array.isArray(payload.fields)) {
        throw new TransferError('Les champs à préparer sont absents ou invalides.');
    }
    const keys = Object.keys(payload.fields).sort();
    if (keys.length !== FIELD_KEYS.length || keys.some((key, index) => key !== [...FIELD_KEYS].sort()[index])) {
        throw new TransferError('La structure des champs à préparer ne correspond pas au format autorisé.');
    }

    const fields = {};
    for (const key of FIELD_KEYS) {
        const value = payload.fields[key];
        if (typeof value !== 'string' || value.trim() === '' || value.length > MAX_FIELD_LENGTH || /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/.test(value)) {
            throw new TransferError(`Valeur absente ou invalide pour le champ ${key}.`);
        }
        fields[key] = value;
    }

    if (fields.pcDepense !== PC_DEPENSE_VALUE || fields.pieceJust !== 'OP') {
        throw new TransferError('Valeur fixe PC Dépense ou Référence pièce(s) invalide.');
    }
    if (fields.assignataire !== ASSIGNE_VALUE) {
        throw new TransferError('Valeur fixe assignataire invalide.');
    }
    if (!/^\d{4}$/.test(fields.exercice)) {
        throw new TransferError('Exercice invalide.');
    }
    if (normalizeText(fields.objetDepense) !== normalizeText(OBJET_DEPENSE)) {
        throw new TransferError('Objet de dépense invalide.');
    }
    if (!/^\d+(?:\.\d{1,2})?$/.test(fields.montant)) {
        throw new TransferError('NET À PAYER invalide.');
    }
    let validDate = false;
    if (/^\d{4}-\d{2}-\d{2}$/.test(fields.dateEmission)) {
        const [year, month, day] = fields.dateEmission.split('-').map(Number);
        const parsedDate = new Date(Date.UTC(year, month - 1, day));
        validDate = !Number.isNaN(parsedDate.getTime())
            && parsedDate.toISOString().slice(0, 10) === fields.dateEmission;
    }
    if (!validDate) {
        throw new TransferError('Date d’émission invalide.');
    }

    return { op_id: payload.op_id, fields };
}

function normalizeText(value) {
    return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim().toLowerCase();
}

function normalizeAmount(value) {
    if (typeof value !== 'string') {
        return null;
    }
    // Nettoie les suffixes monétaires ("Ar", "Ariary", etc.), les séparateurs de milliers
    // (espaces classiques, espaces insécables \u00A0, espaces fines \u202F, apostrophes)
    // et normalise la virgule décimale en point.
    let cleaned = value.trim()
        .replace(/\s*(?:ar|ariary|mga)\b/gi, '')
        .replace(/[\s\u00A0\u202F'’]/g, '')
        .replace(',', '.');

    if (cleaned === '' || !/^\d+(?:\.\d{1,2})?$/.test(cleaned)) {
        return null;
    }

    const [integerPart, decimalPart = ''] = cleaned.split('.');
    return parseInt(integerPart, 10) * 100 + parseInt((decimalPart + '00').slice(0, 2), 10);
}

function amountsMatch(a, b) {
    const centimesA = normalizeAmount(a);
    const centimesB = normalizeAmount(b);
    return centimesA !== null && centimesB !== null && centimesA === centimesB;
}


async function fillMontantField(page, locator, value) {
    // 1. Adapter la valeur pour Inputmask (radixPoint: "," et numericInput: true) :
    // Si les centimes sont nuls (.00), on transmet l'entier "30000" sans décimales.
    // S'il y a des centimes fractionnaires non nuls (ex: "30000.50"), on utilise la virgule "30000,50".
    const hasZeroCents = /^\d+\.00?$/.test(value);
    const montantPourMasque = hasZeroCents
        ? value.replace(/\.00?$/, '')
        : value.replace('.', ',');

    const result = await locator.evaluate((element, { montantMasque, rawValue }) => {
        if (element.inputmask && typeof element.inputmask.setValue === 'function') {
            element.inputmask.setValue(montantMasque);

            element.dispatchEvent(new Event('input', { bubbles: true }));
            element.dispatchEvent(new Event('change', { bubbles: true }));

            return {
                affiche: element.value,
                nonMasque: typeof element.inputmask.unmaskedvalue === 'function'
                    ? element.inputmask.unmaskedvalue()
                    : element.value
            };
        }

        // Repli pour environnement sans Inputmask
        element.value = montantMasque;
        element.dispatchEvent(new Event('input', { bubbles: true }));
        element.dispatchEvent(new Event('change', { bubbles: true }));

        return {
            affiche: element.value,
            nonMasque: element.value
        };
    }, { montantMasque: montantPourMasque, rawValue: value });

    // 2. Vérifier la conformité de la valeur affichée et de la valeur non masquée
    if (
        !amountsMatch(result.affiche, value) ||
        !amountsMatch(result.nonMasque, value)
    ) {
        throw new TransferError(
            'Le montant affiché ou sa valeur non masquée ne correspond pas au montant attendu.'
        );
    }
}


async function pageHasTransferForm(page) {
    try {
        const present = await Promise.all(Object.values(FIELD_IDS).map((id) =>
            page.locator(`#${id}`).count()
        ));
        return present.every((count) => count === 1);
    } catch {
        return false;
    }
}

async function locateFormPage(context, target) {
    const formUrl = target instanceof URL ? target : new URL(target);

    for (const page of context.pages()) {
        let currentOrigin;
        try {
            currentOrigin = new URL(page.url()).origin;
        } catch {
            continue;
        }
        if (currentOrigin === formUrl.origin && await pageHasTransferForm(page)) {
            return page;
        }
    }

    let page = null;
    for (const candidate of context.pages()) {
        try {
            if (new URL(candidate.url()).origin === formUrl.origin) {
                page = candidate;
                break;
            }
        } catch {
            if (candidate.url() === 'about:blank') {
                page = candidate;
            }
        }
    }

    if (!page) {
        page = context.pages().find((candidate) => candidate.url() === 'about:blank')
            || await context.newPage();
    }

    try {
        await page.goto(formUrl.href, { waitUntil: 'domcontentloaded', timeout: 20000 });
    } catch {
        throw new TransferError(
            'Le lien direct du formulaire est inaccessible. Vérifiez le réseau interne et la fenêtre Chrome.',
            503
        );
    }

    try {
        await Promise.all(Object.values(FIELD_IDS).map((id) =>
            page.locator(`#${id}`).waitFor({ state: 'attached', timeout: 12000 })
        ));
    } catch {
        throw new TransferError(
            'Le formulaire ne s’est pas affiché. Connectez-vous manuellement dans cette fenêtre Chrome, puis relancez le transfert.',
            409
        );
    }

    if (new URL(page.url()).origin !== formUrl.origin || !await pageHasTransferForm(page)) {
        throw new TransferError(
            'Le portail a redirigé vers une autre page. Connectez-vous manuellement dans cette fenêtre Chrome et revenez au formulaire.',
            409
        );
    }
    return page;
}

async function readDateLabel(page, id) {
    return page.locator(`#${id}`).evaluate((element) => {
        const label = document.querySelector(`label[for="${element.id}"]`) || element.closest('label');
        const labelledBy = (element.getAttribute('aria-labelledby') || '')
            .split(/\s+/)
            .filter(Boolean)
            .map((labelId) => document.getElementById(labelId)?.textContent || '')
            .join(' ');
        return [
            label?.textContent || '',
            element.getAttribute('aria-label') || '',
            labelledBy
        ].join(' ').trim();
    });
}

async function readFieldLabel(page, id) {
    return page.locator(`#${id}`).evaluate((element) => {
        const label = document.querySelector(`label[for="${element.id}"]`) || element.closest('label');
        const labelledBy = (element.getAttribute('aria-labelledby') || '')
            .split(/\s+/)
            .filter(Boolean)
            .map((labelId) => document.getElementById(labelId)?.textContent || '')
            .join(' ');
        return [
            label?.textContent || '',
            element.getAttribute('aria-label') || '',
            labelledBy
        ].join(' ').trim();
    });
}

async function getSelect2Selection(page, id) {
    return page.locator(`#${id}`).evaluate((element) => {
        let sibling = element.nextElementSibling;
        while (sibling && !sibling.classList.contains('select2')) {
            sibling = sibling.nextElementSibling;
        }
        const rendered = sibling?.querySelector('.select2-selection__rendered');
        return rendered ? {
            text: rendered.textContent?.trim() || '',
            title: rendered.getAttribute('title') || '',
            placeholder: rendered.classList.contains('select2-selection__placeholder')
        } : null;
    });
}

function select2DisplaysOption(selection, expectedLabel, key) {
    if (!selection || selection.placeholder) {
        return false;
    }

    const expected = normalizeText(expectedLabel);
    const text = normalizeText(selection.text);
    const title = normalizeText(selection.title);
    if (expected === '') {
        return false;
    }

    if (key === 'pcDepense') {
        const expectedPcLabel = normalizePcDepenseLabel(expected);
        const visiblePcLabel = normalizePcDepenseLabel(text);
        const titlePcLabel = normalizePcDepenseLabel(title);

        // Select2 may render the option label with its code prefix, or include
        // extra visible text such as a clear control. The underlying option
        // value and unique option label were already checked before selection.
        return visiblePcLabel.includes(expectedPcLabel)
            || titlePcLabel === expectedPcLabel;
    }

    if (key === 'assignataire') {
        // Select2 peut afficher le code, le libellé complet ou une forme
        // tronquée ; on accepte dès que le code 11001.100 est présent.
        const code = normalizeText('11001.100');
        return text.includes(code) || title.includes(code) || text === expected || title === expected;
    }

    return text === expected
        || (title === expected && [
            `${expected} `,
            `${expected} (`,
            `${expected} -`
        ].some((prefix) => text.startsWith(prefix)));
}

function normalizePcDepenseLabel(label) {
    return normalizeText(label).replace(/^4786188\s*-\s*/, '');
}

async function inspectSelect(page, id, key, requestedValue, requireSelect2 = true) {
    const locator = page.locator(`#${id}`);
    const options = await locator.locator('option').evaluateAll((elements) =>
        elements.map((option) => ({
            value: option.value,
            label: option.textContent.trim(),
            disabled: option.disabled
        }))
    );
    let matches;
    if (key === 'compte') {
        const requestedLabel = normalizeText(requestedValue);
        matches = options.filter((option) =>
            option.value === requestedValue || normalizeText(option.label) === requestedLabel
        );
    } else if (key === 'assignataire') {
        const req = requestedValue.trim();
        const reqNorm = normalizeText(req);
        const code = req.split(/[\s\/-]+/)[0].trim();
        matches = options.filter((option) => {
            const optVal = option.value.trim();
            const optNorm = normalizeText(option.label);
            return optVal === req
                || (code !== '' && optVal === code)
                || optNorm === reqNorm
                || (code !== '' && optNorm.includes(code))
                || optNorm.includes(reqNorm)
                || reqNorm.includes(optNorm);
        });
    } else {
        matches = options.filter((option) => option.value === requestedValue);
    }

    if (matches.length !== 1) {
        throw new TransferError(
            matches.length === 0
                ? `Aucune option unique correspondant au snapshot n’est disponible pour ${key}.`
                : `Plusieurs options correspondent au snapshot pour ${key}; sélection ambiguë refusée.`
        );
    }

    const option = matches[0];
    if (option.disabled || !option.label) {
        throw new TransferError(`L’option attendue pour ${key} est désactivée ou sans libellé.`);
    }
    if (key === 'pcDepense'
        && normalizePcDepenseLabel(option.label) !== normalizePcDepenseLabel(PC_DEPENSE_LABEL)) {
        throw new TransferError('Le libellé de l’option PC dépenses ne correspond pas au libellé attendu.');
    }

    const select2 = await getSelect2Selection(page, id);
    if (requireSelect2 && select2 === null) {
        throw new TransferError(`Le composant Select2 de ${key} n’est pas détecté; aucune sélection n’a été faite.`);
    }

    return {
        option,
        currentValue: await locator.inputValue(),
        select2
    };
}

async function fillOfficialForm(page, untrustedPayload, onFillStarted) {
    const payload = validatePayload(untrustedPayload);
    const fields = payload.fields;
    const warnings = [];
    const operations = [];
    const initiallySelectedLabels = new Map();

    // Inspecter et valider tous les contrôles avant la première écriture.
    for (const [key, id] of Object.entries(FIELD_IDS)) {
        const locator = page.locator(`#${id}`);
        const control = await locator.evaluate((element) => ({
            tagName: element.tagName.toLowerCase(),
            type: element.getAttribute('type') || '',
            value: element.value,
            disabled: element.disabled,
            readOnly: element.readOnly
        }));

        if (key === 'pcDepense' || key === 'assignataire') {
            if (control.tagName !== 'select') {
                throw new TransferError(`Le champ ${id} n’est plus une liste de sélection.`);
            }
            if (control.disabled) {
                throw new TransferError(`La liste de sélection ${key} est désactivée.`);
            }
            const inspected = await inspectSelect(page, id, key, fields[key], key === 'pcDepense');
            initiallySelectedLabels.set(key, inspected.option.label);
            if (inspected.currentValue === inspected.option.value) {
                if (inspected.select2 && !select2DisplaysOption(inspected.select2, inspected.option.label, key)) {
                    if (key === 'assignataire') {
                        warnings.push(ASSIGNATAIRE_DISPLAY_WARNING);
                    } else {
                        throw new TransferError(
                            `La valeur déjà sélectionnée pour ${key} n’est pas clairement affichée par Select2.`
                        );
                    }
                }
            } else {
                operations.push({ key, id, type: 'select', value: inspected.option.value });
            }
        } else if (key === 'compte' && control.tagName === 'select') {
            if (control.disabled) {
                throw new TransferError('La liste du compte est désactivée.');
            }
            const inspected = await inspectSelect(page, id, key, fields[key], false);
            initiallySelectedLabels.set(key, inspected.option.label);
            if (inspected.currentValue === inspected.option.value) {
                if (inspected.select2
                    && !select2DisplaysOption(inspected.select2, inspected.option.label, key)) {
                    throw new TransferError('Le libellé du compte sélectionné ne correspond pas à son option.');
                }
                if (!inspected.select2
                    && normalizeText(await locator.locator('option:checked').textContent()) !== normalizeText(inspected.option.label)) {
                    throw new TransferError('Le libellé du compte sélectionné ne correspond pas à son option.');
                }
            } else {
                operations.push({ key, id, type: 'select', value: inspected.option.value });
            }
        } else if (control.tagName !== 'input' && control.tagName !== 'textarea') {
            throw new TransferError(`Le type du champ ${id} a changé.`);
        } else if (key === 'dateEmission') {
            const dateLabel = normalizeText(await readDateLabel(page, id));
            const dateType = control.type;
            if (!/emission|visa/i.test(dateLabel)) {
                warnings.push('Date laissée intacte : le libellé visible ne confirme pas une date d’émission.');
            } else if (dateType !== 'date' && dateType !== 'text') {
                warnings.push('Date laissée intacte : le type HTML du champ est inattendu.');
            } else if (control.disabled || control.readOnly) {
                if (control.value !== fields[key]) {
                    warnings.push('Date laissée intacte : le champ est désactivé ou en lecture seule.');
                }
            } else if (control.value !== fields[key]) {
                operations.push({ key, id, type: 'fill', value: fields[key] });
            }
        } else if (key === 'compte') {
            const label = normalizeText(await readFieldLabel(page, id));
            if (!label.includes('compte')) {
                throw new TransferError('Le libellé visible du champ Compte n’est pas identifiable.');
            }
            if (control.type !== 'text') {
                throw new TransferError('Le champ Compte n’est pas un texte libre; format attendu non établi.');
            }
            if (control.disabled || control.readOnly) {
                if (control.value !== fields[key]) {
                    throw new TransferError('Le champ Compte est désactivé ou en lecture seule et sa valeur diffère.');
                }
            } else if (control.value !== fields[key]) {
                operations.push({ key, id, type: 'fill', value: fields[key] });
            }
        } else if (key === 'montant') {
            if (control.type !== 'text') {
                throw new TransferError(`Le type HTML du champ ${key} n’est pas pris en charge.`);
            }
            if (control.disabled || control.readOnly) {
                if (!amountsMatch(control.value, fields[key])) {
                    throw new TransferError(`Le champ ${key} est désactivé ou en lecture seule et sa valeur diffère.`);
                }
            } else if (!amountsMatch(control.value, fields[key])) {
                operations.push({ key, id, type: 'montant', value: fields[key] });
            }
        } else {
            if (control.type !== 'text' && control.type !== 'date') {
                throw new TransferError(`Le type HTML du champ ${key} n’est pas pris en charge.`);
            }
            if (control.disabled || control.readOnly) {
                if (control.value !== fields[key]) {
                    throw new TransferError(`Le champ ${key} est désactivé ou en lecture seule et sa valeur diffère.`);
                }
            } else if (control.value !== fields[key]) {
                operations.push({ key, id, type: 'fill', value: fields[key] });
            }
        }
    }

    let fillStarted = false;
    const verified = new Set(FIELD_KEYS.filter((key) =>
        key !== 'dateEmission' || !warnings.some((warning) => warning.startsWith('Date laissée intacte'))
    ).filter((key) =>
        key !== 'assignataire' || !warnings.includes(ASSIGNATAIRE_DISPLAY_WARNING)
    ));

    for (const operation of operations) {
        const { key, id, type, value } = operation;
        const locator = page.locator(`#${id}`);
        if (!fillStarted) {
            if (typeof onFillStarted === 'function') {
                const callbackResult = onFillStarted();
                if (callbackResult && typeof callbackResult.then === 'function') {
                    await callbackResult;
                }
            }
            fillStarted = true;
        }

        if (type === 'select') {
            await locator.selectOption({ value });
            if (await locator.inputValue() !== value) {
                throw new TransferError(
                    `La valeur sélectionnée pour ${key} n’a pas pu être vérifiée.`
                );
            }

            const expectedLabel = initiallySelectedLabels.get(key)
                || await locator.locator('option:checked').textContent();
            const deadline = Date.now() + 2000;
            let selection = await getSelect2Selection(page, id);
            if (selection === null) {
                if (key === 'assignataire') {
                    warnings.push(ASSIGNATAIRE_DISPLAY_WARNING);
                    verified.delete('assignataire');
                }
                continue;
            }
            while (!select2DisplaysOption(selection, expectedLabel, key) && Date.now() < deadline) {
                await page.waitForTimeout(100);
                selection = await getSelect2Selection(page, id);
            }

            if (expectedLabel === '' || !select2DisplaysOption(selection, expectedLabel, key)) {
                if (key === 'assignataire') {
                    warnings.push(ASSIGNATAIRE_DISPLAY_WARNING);
                    verified.delete('assignataire');
                } else {
                    throw new TransferError(
                        `La valeur visible du composant Select2 ${key} ne correspond pas à la sélection.`
                    );
                }
            }
        } else if (type === 'montant') {
            await fillMontantField(page, locator, value);
        } else {
            await locator.fill(value);
            if (key === 'objetDepense') {
                if (normalizeText(await locator.inputValue()) !== normalizeText(value)) {
                    throw new TransferError(
                        `La valeur du champ ${key} n’a pas pu être vérifiée.`
                    );
                }
            } else if (await locator.inputValue() !== value) {
                throw new TransferError(
                    `La valeur du champ ${key} n’a pas pu être vérifiée.`
                );
            }
        }
    }

    return { op_id: payload.op_id, verified: verified.size, warnings };
}

module.exports = {
    FIELD_IDS,
    TransferError,
    amountsMatch,
    fillOfficialForm,
    locateFormPage,
    normalizeAmount,
    pageHasTransferForm,
    validatePayload
};
