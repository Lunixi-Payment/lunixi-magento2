const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync(require('node:path').join(__dirname, '../view/frontend/web/js/checkout.js'), 'utf8');
function component(load, globalSdk) {
  let render;
  const notice = {hidden: false, textContent: 'Loading'};
  vm.runInNewContext(code, {window: {Lunixi: globalSdk}, document: {getElementById: () => notice}, define: (deps, factory) => {render = factory(load, value => value);}});
  return {render, notice};
}
test('AMD SDK receives structured native configuration and the declared element', () => {
  const config = {type: 'payment', target: 'lunixi-checkout-form', options: {token: 'synthetic-browser-token'}};
  let received, selector;
  const sdk = {checkout: {create(value) {received = value; return {mount(value) {selector = value;}};}}};
  const {render, notice} = component((deps, done) => done(sdk));
  render(config, {id: 'lunixi-checkout-form'});
  assert.equal(received, config); assert.equal(selector, '#lunixi-checkout-form'); assert.equal(notice.hidden, true);
});
test('global SDK works and asynchronous or CDN failure displays a safe localized message', () => {
  const sdk = {checkout: {create() {return {mount() {return {catch(fail) {fail(new Error('synthetic transport detail'));}};}};}}};
  const loaded = component((deps, done) => done(undefined), sdk);
  loaded.render({options: {token: 'synthetic'}}, {id: 'lunixi-checkout-form'});
  assert.equal(loaded.notice.hidden, false); assert.match(loaded.notice.textContent, /could not load/); assert.doesNotMatch(loaded.notice.textContent, /transport detail/);
  const missing = component((deps, done, fail) => fail(new Error('network')));
  missing.render({}, {id: 'lunixi-checkout-form'});
  assert.equal(missing.notice.hidden, false); assert.match(missing.notice.textContent, /could not load/);
});
