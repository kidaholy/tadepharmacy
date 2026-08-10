// Sidebar toggle
function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  sidebar.classList.toggle('open');
  if (overlay) overlay.classList.toggle('open');
}

// Live clock
function updateClock() {
  const now = new Date();
  const timeEl = document.getElementById('sidebarTime');
  const dateEl = document.getElementById('topbarDate');
  const opts = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
  if (timeEl) timeEl.textContent = now.toLocaleTimeString();
  if (dateEl) dateEl.textContent = now.toLocaleDateString('en-US', opts);
}
updateClock();
setInterval(updateClock, 1000);

// Modal helpers
function openModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.add('open');
}
function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('open');
}

// Auto-hide alerts
document.querySelectorAll('.alert').forEach(el => {
  if (el.classList.contains('auto-hide')) {
    setTimeout(() => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(-8px)';
      setTimeout(() => el.remove(), 300);
    }, 3500);
  }
});

// Confirm delete
function confirmDelete(form) {
  if (confirm('Are you sure you want to delete this record? This action cannot be undone.')) {
    form.submit();
  }
  return false;
}
