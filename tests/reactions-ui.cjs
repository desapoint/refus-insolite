'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../public/assets/site.js', 'utf8');

function classes() {
  const state = new Set();
  return {
    state,
    add(...names) { for (const name of names) state.add(name); },
    remove(...names) { for (const name of names) state.delete(name); },
    toggle(name, yes) { yes ? state.add(name) : state.delete(name); }
  };
}
function setup(fetchImpl) {
  const feedback = {textContent:'',hidden:true,classList:classes()};
  const celebration = {offsetWidth:1,classList:classes()};
  const panel = {offsetWidth:1,classList:classes()};
  const forms = ['relate','laugh'].map(kind => {
    const button = {disabled:false,classList:classes()};
    const count = {textContent:'0'};
    const form = {
      action:{tagName:'INPUT'},getAttribute(name) {assert.equal(name,'action');return '/refus/123';},dataset:{kind},button,count,callback:null,
      querySelector(selector) {
        return selector === 'button[type="submit"]' ? button
          : selector === '[data-count]' ? count : null;
      },
      addEventListener(name,callback) {if(name==='submit')this.callback=callback;}
    };
    return form;
  });
  const doc = {
    getElementById(id) {return ({reactionFeedback:feedback,reactionCelebration:celebration,reactionPanel:panel})[id] || null;},
    querySelectorAll(selector) {return selector === '.reaction-form' ? forms : [];}
  };
  let calls=0;
  vm.runInNewContext(source,{
    document:doc,
    FormData:class {constructor(form){assert(forms.includes(form));}},
    fetch: async (...args)=>{calls++;return fetchImpl(...args);},
    console
  });
  return {forms,feedback,celebration,panel,get calls(){return calls;}};
}
const response = obj => ({ok:true,headers:{get:()=> 'application/json'},json:async()=>obj});
async function submit(form) {await form.callback({preventDefault(){}});}
(async () => {
  let index = 0;
  const answers = [
    {ok:true,added:true,kind:'relate',likes_count:1,laughs_count:0},
    {ok:true,added:true,kind:'laugh',likes_count:1,laughs_count:1}
  ];
  const ui = setup(async()=>response(answers[index++]));
  await submit(ui.forms[0]);
  assert.equal(ui.forms[0].button.disabled,true);
  assert.equal(ui.forms[0].count.textContent,1);
  assert.equal(ui.forms[1].count.textContent,0);
  await submit(ui.forms[0]);
  assert.equal(ui.calls,1,'pas de double vote en cliquant une deuxième fois');
  await submit(ui.forms[1]);
  assert.equal(ui.calls,2);
  assert.equal(ui.forms[1].count.textContent,1);
  assert(ui.celebration.classList.state.has('is-laugh'),'animation rire');
  assert(ui.celebration.classList.state.has('is-celebrating'),'explosion comique');
  assert.match(ui.feedback.textContent,/Fou rire/);

  const failed = setup(async()=>{throw new Error('Connexion momentanément indisponible');});
  await submit(failed.forms[1]);
  assert.equal(failed.forms[1].button.disabled,false,'en cas d’erreur, le bouton redevient cliquable');
  assert.equal(failed.feedback.hidden,false);
  assert(failed.feedback.classList.state.has('error'));
  console.log('Tests AJAX UI réactions : OK');
})().catch(e=>{console.error(e);process.exitCode=1;});
