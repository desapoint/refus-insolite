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
  const stage = { offsetWidth: 100, classList: {
    add(...names) { names.forEach(x => stageClasses.add(x)); },
    remove(...names) { names.forEach(x => stageClasses.delete(x)); }
  }};
  const button = {textContent: 'Envoyer cette pépite →', disabled: false};
  const title = {textContent: ''};
  const message = {textContent: ''};
  const form = {action: '/soumettre', event: null, reportValidity: () => true,
    addEventListener(name, callback) { if (name === 'submit') this.event = callback; },
    reset() { state.resetCount += 1; }};
  const elements = {submitForm: form, submitButton: button, submitFeedback: feedback,
    submitFeedbackTitle: title, submitFeedbackMessage: message, laughStage: stage};
  const window = {getElementById(id) {return elements[id] || null;}};
  vm.runInNewContext(code, {
    document: window,
    fetch: async (url, init) => {state.callCount++; state.sent = {url, init, whileLoading: button.disabled}; return state.resolveFetch();},
    FormData: class {constructor(formRef) {assert.equal(formRef, form);}},
    AbortController: class {constructor() { this.signal = {}; } abort() {}},
    setTimeout: () => 1, clearTimeout: () => undefined, console
  });
  return {form, button, feedback, title, message, state, stageClasses, classes};
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
  assert.equal(success.feedback.hidden, false);
  assert.equal(success.title.textContent, 'AHAHA ! Une pépite de plus !');
  assert(success.stageClasses.has('is-success'));
  assert.equal(success.button.disabled, false);
  const validation = setup(resp(false, 'Titre trop court.'));
  await send(validation);
  assert.equal(validation.state.resetCount, 0, 'Ne jamais vider sur un échec métier');
  assert.equal(validation.message.textContent, 'Titre trop court.');
  assert(validation.classes.has('error'));
  const network = setup(async () => {throw new Error('offline');});
  await send(network);
  assert.equal(network.state.resetCount, 0, 'Ne jamais vider sur une erreur réseau');
  assert.equal(network.feedback.hidden, false);
  console.log('Tests soumission AJAX UI : OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
