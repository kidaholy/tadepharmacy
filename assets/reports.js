function toggleCustomDates() {
  const preset = document.getElementById('reportPreset');
  const show = preset && preset.value === 'custom';
  document.querySelectorAll('.report-custom-dates').forEach(el => {
    el.style.display = show ? '' : 'none';
  });
  ['reportDateFrom', 'reportDateTo'].forEach(id => {
    const input = document.getElementById(id);
    if (input) input.disabled = !show;
  });
}

const CHART_COLORS = ['#2563eb', '#0284c7', '#0ea5e9', '#dc2626', '#d97706', '#1d4ed8', '#0369a1', '#38bdf8'];

function chartDefaults() {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { labels: { color: '#475569', font: { family: 'Inter' } } }
    },
    scales: {
      x: { ticks: { color: '#64748b' }, grid: { color: 'rgba(15, 23, 42, 0.06)' } },
      y: { ticks: { color: '#64748b' }, grid: { color: 'rgba(15, 23, 42, 0.06)' } }
    }
  };
}

function initReportCharts() {
  if (typeof Chart === 'undefined') return;

  Chart.defaults.color = '#64748b';
  Chart.defaults.borderColor = 'rgba(15, 23, 42, 0.08)';
  Chart.defaults.font.family = 'Inter';

  document.querySelectorAll('#chartDailyRevenue').forEach(el => {
    const labels = JSON.parse(el.dataset.labels || '[]');
    const values = JSON.parse(el.dataset.values || '[]');
    const label = el.dataset.label || 'Revenue';
    new Chart(el, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label,
          data: values,
          borderColor: '#2563eb',
          backgroundColor: 'rgba(37, 99, 235, 0.1)',
          fill: true,
          tension: 0.35,
          pointRadius: 3
        }]
      },
      options: chartDefaults()
    });
  });

  document.querySelectorAll('#chartProfit').forEach(el => {
    const labels = JSON.parse(el.dataset.labels || '[]');
    const values = JSON.parse(el.dataset.values || '[]');
    new Chart(el, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Profit',
          data: values,
          backgroundColor: 'rgba(2, 132, 199, 0.75)',
          borderRadius: 6
        }]
      },
      options: chartDefaults()
    });
  });

  document.querySelectorAll('#chartPayments').forEach(el => {
    const labels = JSON.parse(el.dataset.labels || '[]');
    const values = JSON.parse(el.dataset.values || '[]');
    new Chart(el, {
      type: 'doughnut',
      data: {
        labels,
        datasets: [{
          data: values,
          backgroundColor: CHART_COLORS.slice(0, labels.length),
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { color: '#475569', padding: 14 } } }
      }
    });
  });

  document.querySelectorAll('#chartCategories').forEach(el => {
    const labels = JSON.parse(el.dataset.labels || '[]');
    const values = JSON.parse(el.dataset.values || '[]');
    new Chart(el, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Revenue',
          data: values,
          backgroundColor: CHART_COLORS.map(c => c + 'cc'),
          borderRadius: 6
        }]
      },
      options: { ...chartDefaults(), indexAxis: 'y' }
    });
  });

  document.querySelectorAll('#chartTopProducts').forEach(el => {
    const labels = JSON.parse(el.dataset.labels || '[]');
    const values = JSON.parse(el.dataset.values || '[]');
    const label = el.dataset.label || 'Units Sold';
    new Chart(el, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label,
          data: values,
          backgroundColor: 'rgba(37, 99, 235, 0.75)',
          borderRadius: 6
        }]
      },
      options: { ...chartDefaults(), indexAxis: labels.length > 6 ? 'y' : 'x' }
    });
  });

  document.querySelectorAll('#chartCompare').forEach(el => {
    const labels = JSON.parse(el.dataset.labels || '[]');
    const units = JSON.parse(el.dataset.units || '[]');
    const revenue = JSON.parse(el.dataset.revenue || '[]');
    const profit = JSON.parse(el.dataset.profit || '[]');
    const datasets = [];
    if (units.length) datasets.push({ label: 'Units Sold', data: units, backgroundColor: '#2563ebcc', borderRadius: 6 });
    if (revenue.length) datasets.push({ label: 'Revenue', data: revenue, backgroundColor: '#0284c7cc', borderRadius: 6 });
    if (profit.length) datasets.push({ label: 'Gross Profit', data: profit, backgroundColor: '#0ea5e9cc', borderRadius: 6 });
    if (!datasets.length) return;
    new Chart(el, { type: 'bar', data: { labels, datasets }, options: chartDefaults() });
  });
}

function switchProductTrend(view, btn) {
  const el = document.getElementById('chartProductTrend');
  if (!el || !el._chart) return;
  const chart = el._chart;
  const labels = JSON.parse(el.dataset[view + 'Labels'] || '[]');
  const units = JSON.parse(el.dataset[view + 'Units'] || '[]');
  chart.data.labels = labels;
  chart.data.datasets[0].data = units;
  chart.update();
  // Update active button state
  document.querySelectorAll('[data-trend-view]').forEach(b => {
    b.classList.remove('btn-primary');
    b.classList.add('btn-ghost');
  });
  btn.classList.remove('btn-ghost');
  btn.classList.add('btn-primary');
}

function initProductTrendChart() {
  const el = document.getElementById('chartProductTrend');
  if (!el || typeof Chart === 'undefined') return;
  const labels = JSON.parse(el.dataset.dailyLabels || '[]');
  const units = JSON.parse(el.dataset.dailyUnits || '[]');
  const chart = new Chart(el, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Units Sold',
        data: units,
        borderColor: '#2563eb',
        backgroundColor: 'rgba(37, 99, 235, 0.1)',
        fill: true,
        tension: 0.35,
        pointRadius: 3
      }]
    },
    options: chartDefaults()
  });
  el._chart = chart;
}

function initSalesTrendChart() {
  const el = document.getElementById('chartSalesTrend');
  if (!el || typeof Chart === 'undefined') return;
  const labels = JSON.parse(el.dataset.dailyLabels || '[]');
  const values = JSON.parse(el.dataset.dailyValues || '[]');
  const chart = new Chart(el, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Net Sales',
        data: values,
        borderColor: '#2563eb',
        backgroundColor: 'rgba(37, 99, 235, 0.1)',
        fill: true,
        tension: 0.35,
        pointRadius: 3
      }]
    },
    options: chartDefaults()
  });
  el._chart = chart;
}

function switchSalesTrend(view, btn) {
  const el = document.getElementById('chartSalesTrend');
  if (!el || !el._chart) return;
  const chart = el._chart;
  const labels = JSON.parse(el.dataset[view + 'Labels'] || '[]');
  const values = JSON.parse(el.dataset[view + 'Values'] || '[]');
  chart.data.labels = labels;
  chart.data.datasets[0].data = values;
  chart.update();
  document.querySelectorAll('[data-sales-trend-view]').forEach(b => {
    b.classList.remove('btn-primary');
    b.classList.add('btn-ghost');
  });
  btn.classList.remove('btn-ghost');
  btn.classList.add('btn-primary');
}

window.addEventListener('load', () => {
  toggleCustomDates();
  initReportCharts();
  initProductTrendChart();
  initSalesTrendChart();
  if (typeof refreshIcons === 'function') refreshIcons();
});
