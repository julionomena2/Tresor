// Importer Playwright
const { chromium } = require('playwright');

async function main() {
    // Lancer Google Chrome
    const browser = await chromium.launch({
        headless: false,
        executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
    });

    // Créer un nouvel onglet
    const page = await browser.newPage();

    // Ouvrir une page de test
    await page.goto('https://example.com');

    // Afficher le titre de la page
    const titre = await page.title();

    console.log('Titre de la page :', titre);

    // Fermer Chrome
    await browser.close();
}

main().catch(console.error);