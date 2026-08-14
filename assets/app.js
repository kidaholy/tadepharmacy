// Sidebar toggle
function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  if (!sidebar) return;
  sidebar.classList.toggle('open');
  if (overlay) overlay.classList.toggle('open');
}

// Live clock (every 30s is enough — avoids constant DOM writes)
function updateClock() {
  const now = new Date();
  const timeEl = document.getElementById('sidebarTime');
  const dateEl = document.getElementById('topbarDate');
  const opts = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
  if (timeEl) timeEl.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  if (dateEl) dateEl.textContent = now.toLocaleDateString('en-US', opts);
}
updateClock();
setInterval(updateClock, 30000);

// Modal helpers
function openModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.add('open');
}
function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('open');
}

// Confirm delete
function confirmDelete(form) {
  if (confirm('Are you sure you want to delete this record? This action cannot be undone.')) {
    form.submit();
  }
  return false;
}

// Lucide: create icons once, and only for a subtree when needed
function refreshIcons(root) {
  if (typeof lucide === 'undefined' || !lucide.createIcons) return;
  try {
    if (root) {
      lucide.createIcons({
        nameAttr: 'data-lucide',
        attrs: { 'stroke-width': 2 },
        root: root
      });
    } else {
      lucide.createIcons();
    }
  } catch (e) { /* ignore */ }
}

function initPage() {
  refreshIcons();

  document.querySelectorAll('.alert.auto-hide').forEach(el => {
    setTimeout(() => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(-8px)';
      setTimeout(() => el.remove(), 280);
    }, 2800);
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initPage);
} else {
  // Lucide may still be loading (defer) — retry briefly
  initPage();
  window.addEventListener('load', () => refreshIcons());
}
