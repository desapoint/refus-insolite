'use strict';
const menu = document.getElementById('mobileMenu');
const nav = document.getElementById('navLinks');
if (menu && nav) menu.addEventListener('click', () => {
  const open = nav.classList.toggle('open');
  menu.setAttribute('aria-expanded', String(open));
});
const preview = document.getElementById('previewButton');
const modal = document.getElementById('previewModal');
const close = document.getElementById('closeModal');
if (preview && modal && close) {
  preview.addEventListener('click', () => {
    document.getElementById('previewTitle').textContent = document.getElementById('storyTitle').value || 'Votre titre';
    document.getElementById('previewQuote').textContent = document.getElementById('storyQuote').value || 'Votre refus apparaîtra ici.';
    document.getElementById('previewContext').textContent = document.getElementById('storyContext').value || 'Votre contexte apparaîtra ici.';
    modal.classList.add('visible'); modal.setAttribute('aria-hidden', 'false'); close.focus();
  });
  const closeIt = () => { modal.classList.remove('visible'); modal.setAttribute('aria-hidden', 'true'); preview.focus(); };
  close.addEventListener('click', closeIt);
  modal.addEventListener('click', (event) => { if (event.target === modal) closeIt(); });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && modal.classList.contains('visible')) closeIt(); });
}
const copy = document.getElementById('copyButton');
if (copy) copy.addEventListener('click', async () => {
  try { await navigator.clipboard.writeText(location.href); copy.textContent = 'Lien copié !'; }
  catch { copy.textContent = 'Copiez le lien dans la barre d’adresse'; }
});

