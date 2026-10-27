'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync(__dirname + '/../public/assets/site.js', 'utf8');

function setup(response) {
  const classes = new Set();
  const stageClasses = new Set();
  const state = { resetCount: 0, callCount: 0, resolveFetch: response };
  const feedback = {
    hidden: true,
    classList: { toggle(name, val) { val ? classes.add(name) : classes.delete(name); } },
    focus() {}, scrollIntoView() {}
  };
  const stage = { offsetWidth: 100, dataset: {}, classList: {
    add(...names) { names.forEach(x => stageClasses.add(x)); },
    remove(...names) { names.forEach(x => stageClasses.delete(x)); }
  }};
  const buttonClasses = new Set();
  const button = {
    textContent: 'Envoyer cette pépite →', disabled: false,
    classList: {add(name){buttonClasses.add(name);},remove(name){buttonClasses.delete(name);}}
  };
  const title = {textContent: ''};
  const message = {textContent: ''};
  const fullTitle = {textContent: ''};
  const fullSubtitle = {textContent: ''};
  const form = {action: {tagName:'INPUT'}, getAttribute(name) {assert.equal(name,'action');return '/soumettre';}, event: null, reportValidity: () => true,
    addEventListener(name, callback) { if (name === 'submit') this.event = callback; },
    reset() { state.resetCount += 1; }};
  const elements = {submitForm: form, submitButton: button, submitFeedback: feedback,
    submitFeedbackTitle: title, submitFeedbackMessage: message, laughStage: stage,
    laughCaption: fullTitle, laughSubcaption: fullSubtitle};
  const window = {getElementById(id) {return elements[id] || null;}};
  vm.runInNewContext(code, {
    document: window,
    fetch: async (url, init) => {state.callCount++; state.sent = {url, init, whileLoading: button.disabled}; return state.resolveFetch();},
    FormData: class {constructor(formRef) {assert.equal(formRef, form);}},
    AbortController: class {constructor() { this.signal = {}; } abort() {}},
    setTimeout: () => 1, clearTimeout: () => undefined, console
  });
  return {form, button, feedback, title, message, state, stage, stageClasses, classes, fullTitle, fullSubtitle};
}
const resp = (ok, message, status = ok ? 201 : 422) => () => ({
  ok: status < 400, headers: {get() {return 'application/json; charset=UTF-8';}},
  json: async () => ({ok, message})
});
const send = ctx => ctx.form.event({preventDefault() {}});
(async () => {
  const success = setup(resp(true, 'Bien reçu.'));
  await send(success);
  assert.equal(success.state.callCount, 1);
  assert.equal(success.state.sent.url, '/soumettre');
  assert.equal(success.state.sent.init.headers.Accept, 'application/json');
  assert.equal(success.state.sent.whileLoading, true);
  assert.equal(success.state.resetCount, 1);
  assert.equal(success.feedback.hidden, true, 'aucune bannière de succès sous le formulaire');
  assert.equal(success.title.textContent, '', 'le succès se confirme uniquement en plein écran');
  assert(success.stageClasses.has('is-success'),'animation plein écran au succès');
  assert(['belly','chair','jaw','printer'].includes(success.stage.dataset.variant),'variante animée définie');
  assert.equal(success.stage.dataset.stamp,'no','aucun tampon pour les soumissions');
  assert.match(success.fullTitle.textContent,/DANS LA BOÎTE/);
  assert.equal(success.button.disabled, false);
  const validation = setup(resp(false, 'Titre trop court.'));
  await send(validation);
  assert.equal(validation.state.resetCount, 0, 'Ne jamais vider sur un échec métier');
  assert.equal(validation.message.textContent, 'Titre trop court.');
  assert(validation.classes.has('error'));
  assert(!validation.stageClasses.has('is-success'),'pas de fausse célébration en cas de validation refusée');
  const network = setup(async () => {throw new Error('offline');});
  await send(network);
  assert.equal(network.state.resetCount, 0, 'Ne jamais vider sur une erreur réseau');
  assert.equal(network.feedback.hidden, false);
  assert(!network.stageClasses.has('is-success'),'pas de célébration en cas de panne réseau');
  console.log('Tests soumission AJAX UI : OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
