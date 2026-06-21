try {
    require('puppeteer');
    console.log('Puppeteer found');
} catch (e) {
    console.log('Installing puppeteer...');
}
