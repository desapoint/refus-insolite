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



// Grand fou rire dessiné en SVG : partagé par les deux types de réactions et la soumission.
// Une nouvelle interaction relance toute la chorégraphie, sans son ni ressource distante.
const fullScreenLaugh = document.getElementById('laughStage');
let laughFadeTimer;
let laughHideTimer;
function playFullScreenLaugh(kind) {
  if (!fullScreenLaugh) return;
  clearTimeout(laughFadeTimer);
  clearTimeout(laughHideTimer);
  const scenes = {
    submit: ["C'EST DANS LA BOÎTE !", "Le jury a besoin d'une minute pour reprendre son souffle."],
    laugh: ["AHAHA ! C'EST DU GÉNIE !", "Ce refus vient officiellement de nous achever."],
    relate: ["AH NON, PAS TOI AUSSI !", "Bienvenue dans le club très sélect des refus absurdes."]
  };
  const [title, subtitle] = scenes[kind] || scenes.laugh;
  const label = document.getElementById('laughCaption');
  const detail = document.getElementById('laughSubcaption');
  if (label) label.textContent = title;
  if (detail) detail.textContent = subtitle;
  if (fullScreenLaugh.dataset) fullScreenLaugh.dataset.kind = kind;
  fullScreenLaugh.classList.remove('is-success', 'is-sending', 'is-leaving');
  void fullScreenLaugh.offsetWidth;
  fullScreenLaugh.classList.add('is-success');
  laughFadeTimer = setTimeout(() => {
    fullScreenLaugh.classList.add('is-leaving');
    laughHideTimer = setTimeout(() => {
      fullScreenLaugh.classList.remove('is-success', 'is-leaving');
    }, 360);
  }, 3000);
}

 // Soumission asynchrone : on ne perd jamais le récit en cas de refus du serveur.
 const submitForm = document.getElementById('submitForm');
 if (submitForm) {
   const button = document.getElementById('submitButton');
   const feedback = document.getElementById('submitFeedback');
   const feedbackTitle = document.getElementById('submitFeedbackTitle');
   const feedbackMessage = document.getElementById('submitFeedbackMessage');
   const laughStage = fullScreenLaugh;
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
     button.classList.add('is-laugh-pending');
     const controller = new AbortController();
     const timeout = setTimeout(() => controller.abort(), 20000);

     try {
       const response = await fetch(submitForm.getAttribute('action'), {
         method: 'POST',
         body: new FormData(submitForm),
         credentials: 'same-origin',
         headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
         signal: controller.signal
       });
       if (!(response.headers.get('content-type') || '').toLowerCase().includes('application/json')) {
         setFeedback(false, 'La boîte à refus fait des siennes',
           'Le site n’a pas confirmé l’envoi (code ' + response.status + '). Votre texte est conservé. Réessayez plus tard ou signalez le problème.');
         return;
       }
       const result = await response.json();
       if (!response.ok || result.ok !== true) {
         setFeedback(false, 'Oups, pas encore !',
           typeof result.message === 'string' ? result.message : 'Vérifiez votre histoire et réessayez.');
         return;
       }
       submitForm.reset();
       playFullScreenLaugh('submit');
       setFeedback(true, 'AHAHA ! Une pépite de plus !',
         typeof result.message === 'string' ? result.message : 'Votre histoire attend la modération avant publication.');
     } catch (error) {
       setFeedback(false, 'Le rire attendra un peu…',
         error && error.name === 'AbortError'
           ? 'La réponse prend trop de temps. Vérifiez avant de renvoyer pour éviter un doublon.'
           : 'Impossible de confirmer la réception. Vérifiez votre connexion avant de réessayer.');
     } finally {
       clearTimeout(timeout);
       sending = false;
       button.disabled = false;
       button.classList.remove('is-laugh-pending');
       button.textContent = originalButtonText;
     }
   });
 }


// Réactions sans rechargement, distinctes et amusantes.
if (typeof document.querySelectorAll === 'function') {
  document.querySelectorAll('.reaction-form').forEach((form) => {
    const button = form.querySelector('button[type="submit"]');
    const count = form.querySelector('[data-count]');
    const feedback = document.getElementById('reactionFeedback');
    const celebration = document.getElementById('reactionCelebration');
    const panel = document.getElementById('reactionPanel');
    if (!button || !count || !feedback || !celebration || !panel) return;
    let sending = false;
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (sending || button.disabled) return;
      sending = true;
      button.disabled = true;
      button.classList.add('is-reacting');
      feedback.hidden = true;
      const kind = form.dataset.kind;
      try {
        const response = await fetch(form.getAttribute('action'), {
          method: 'POST',
          body: new FormData(form),
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        const type = (response.headers.get('content-type') || '').toLowerCase();
        if (!type.includes('application/json')) throw new Error('Le serveur n’a pas confirmé la réaction (' + response.status + ').');
        const result = await response.json();
        if (!response.ok || result.ok !== true) throw new Error(result.message || 'Réaction non enregistrée.');
        document.querySelectorAll('.reaction-form').forEach((other) => {
          const value = other.querySelector('[data-count]');
          if (!value) return;
          value.textContent = other.dataset.kind === 'laugh' ? result.laughs_count : result.likes_count;
        });
        button.classList.add('is-picked');
        if (result.added) {
          playFullScreenLaugh(kind === 'laugh' ? 'laugh' : 'relate');
          celebration.classList.remove('is-celebrating', 'is-laugh');
          void celebration.offsetWidth;
          celebration.classList.toggle('is-laugh', kind === 'laugh');
          celebration.classList.add('is-celebrating');
          panel.classList.remove('reaction-bounce');
          void panel.offsetWidth;
          panel.classList.add('reaction-bounce');
        }
        feedback.textContent = result.added
          ? (kind === 'laugh' ? '😂 Fou rire validé ! Le jury est par terre.' : '🙋 Bienvenue au club des refus lunaires !')
          : 'Vous aviez déjà réagi à ce témoignage.';
        feedback.classList.remove('error');
        feedback.hidden = false;
      } catch (error) {
        feedback.textContent = (error && error.message) || 'Impossible d’enregistrer la réaction. Réessayez.';
        feedback.classList.add('error');
        feedback.hidden = false;
        button.disabled = false;
      } finally {
        sending = false;
        button.classList.remove('is-reacting');
      }
    });
  });
}
