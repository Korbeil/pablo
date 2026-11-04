// Bulma is an importmap CSS package (see importmap.php): importing it from JS
// is what makes importmap() emit its <link rel="stylesheet">. A CSS @import
// would be resolved as a relative path instead and fail.
import 'bulma/css/bulma.min.css';

import './bootstrap.js';
import './styles/app.css';

// Charts (analytics page) use the dashboard's font. symfony/ux-chartjs fires
// chartjs:init once, with the Chart class, before the first chart is built.
document.addEventListener('chartjs:init', (event) => {
    const { Chart } = event.detail;
    const css = getComputedStyle(document.documentElement);
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    // Theme-aware text and grid colours (read once: a theme switch shows on
    // the next load, the page has no live charts to repaint).
    Chart.defaults.color = css.getPropertyValue('--pablo-muted').trim() || Chart.defaults.color;
    Chart.defaults.borderColor = css.getPropertyValue('--pablo-line-strong').trim() || Chart.defaults.borderColor;
});
