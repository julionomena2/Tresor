const { chromium } = require('playwright');

async function main() {
    const browser = await chromium.launch({
        headless: false,
        executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
    });

    const page = await browser.newPage();

    // Créer un formulaire de test
    await page.setContent(`
        <h2>Formulaire de test</h2>

        <label>Code tiers</label>
        <input id="codeTiers" type="text">

        <label>Titulaire</label>
        <input id="titulaire" type="text">

        <label>Montant</label>
        <input id="montant" type="text">

        <label>PC Dépense</label>
        <select id="pcDepense">
            <option value="">Choisir</option>
            <option value="5811">Virement de fonds</option>
            <option value="18431">Compte de liaison</option>
        </select>
    `);

    // Remplir les champs
    await page.locator('#codeTiers').fill('123456');
    await page.locator('#titulaire').fill('Jean Dupont');
    await page.locator('#montant').fill('150000');

    // Sélectionner une option
    await page.locator('#pcDepense').selectOption('5811');

    // Vérifier les valeurs
    console.log('Code tiers :', await page.locator('#codeTiers').inputValue());
    console.log('Titulaire :', await page.locator('#titulaire').inputValue());
    console.log('Montant :', await page.locator('#montant').inputValue());
    console.log('PC Dépense :', await page.locator('#pcDepense').inputValue());

    await browser.close();
}

main().catch(console.error);
