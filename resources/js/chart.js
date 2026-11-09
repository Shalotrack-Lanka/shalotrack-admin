import { Chart, registerables } from 'chart.js';

// The dashboard charts only; loaded on that one page (kept out of app.js so every other page stays small).
Chart.register(...registerables);
window.Chart = Chart;
