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



 // Soumission asynchrone : on ne perd jamais le récit en cas de refus du serveur.
 const submitForm = document.getElementById('submitForm');
 if (submitForm) {
   const button = document.getElementById('submitButton');
   const feedback = document.getElementById('submitFeedback');
   const feedbackTitle = document.getElementById('submitFeedbackTitle');
   const feedbackMessage = document.getElementById('submitFeedbackMessage');
   const laughStage = document.getElementById('laughStage');
   const originalButtonText = button.textContent;
   let sending = false;

   const setFeedback = (ok, title, message) => {
     feedbackTitle.textContent = title;
     feedbackMessage.textContent = message;
     feedback.classList.toggle('success', ok);
     feedback.classList.toggle('error', !ok);
     feedback.hidden = false;
     feedback.focus({ preventScroll: true });
     feedback.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
   };

   submitForm.addEventListener('submit', async (event) => {
     event.preventDefault();
     if (sending || !submitForm.reportValidity()) return;
     sending = true;
     button.disabled = true;
     button.textContent = 'Le jury essaie de ne pas rire…';
     feedback.hidden = true;
     laughStage.classList.remove('is-success');
     laughStage.classList.add('is-sending');
     const controller = new AbortController();
     const timeout = setTimeout(() => controller.abort(), 20000);

     try {
       const response = await fetch(submitForm.action, {
         method: 'POST',
         body: new FormData(submitForm),
         credentials: 'same-origin',
         headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
         signal: controller.signal
       });
       if (!(response.headers.get('content-type') || '').toLowerCase().includes('application/json')) {
         throw new Error('Réponse inattendue');
       }
       const result = await response.json();
       if (!response.ok || result.ok !== true) {
         laughStage.classList.remove('is-sending', 'is-success');
         setFeedback(false, 'Oups, pas encore !',
           typeof result.message === 'string' ? result.message : 'Vérifiez votre histoire et réessayez.');
         return;
       }
       submitForm.reset();
       laughStage.classList.remove('is-sending');
       // Relance l'animation même pour un deuxième témoignage envoyé.
       void laughStage.offsetWidth;
       laughStage.classList.add('is-success');
       setFeedback(true, 'AHAHA ! Une pépite de plus !',
         typeof result.message === 'string' ? result.message : 'Votre histoire attend la modération avant publication.');
     } catch (error) {
       laughStage.classList.remove('is-sending', 'is-success');
       setFeedback(false, 'Le rire attendra un peu…',
         error && error.name === 'AbortError'
           ? 'La réponse prend trop de temps. Vérifiez avant de renvoyer pour éviter un doublon.'
           : 'Impossible de confirmer la réception. Vérifiez votre connexion avant de réessayer.');
     } finally {
       clearTimeout(timeout);
       sending = false;
       button.disabled = false;
       button.textContent = originalButtonText;
     }
   });
 }
