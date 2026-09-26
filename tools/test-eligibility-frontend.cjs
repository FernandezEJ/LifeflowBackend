// ========================================
// FRONTEND TRANSPORT CONTRACT TESTS
// Executes the actual TypeScript API modules with mocked HTTP responses.
// No accounts or medical data are sent to a running server.
// ========================================
const fs = require('fs');
const vm = require('vm');
const assert = require('node:assert/strict');
const root = 'C:/Capstone-LifeFlow/lifeflow-frontend';
const ts = require(root + '/node_modules/typescript');
let calls = [];
let reply;
function load(name, dependencies = {}) {
  const source = fs.readFileSync(root + '/src/services/' + name + '.ts', 'utf8');
  const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const exports = {};
  vm.runInNewContext(code, { exports, require: (id) => dependencies[id], process: { env: {} },
    AbortController, setTimeout, clearTimeout,
    fetch: async (url, options) => { calls.push({ url, options }); return { ok: reply.status < 400, status: reply.status, json: async () => reply.data }; },
  });
  return exports;
}
const api = load('api');
const { eligibilityApi, RESULT_LABELS, SCREENING_NOTICE } = load('eligibility', { './api': api });

// ========================================
// ALL RESULT STATES AND SAFE ERRORS
// Verifies bearer headers, answer-only payloads, server reasons, empty history,
// and readable validation/authentication failures.
// ========================================
(async () => {
  const answers = { weight: 50, sleepHours: 5, currentSymptoms: 'NO', medication: 'NO', donatedWithinThreeMonths: 'NO', feelsWell: 'YES' };
  for (const result of ['eligible', 'temporarily_ineligible', 'needs_further_screening']) {
    reply = { status: 201, data: { assessment: { id: 1, result, reasons: ['Server reason'], answers, assessed_at: '2026-09-07T00:00:00Z' } } };
    const response = await eligibilityApi.submit('test-token', answers);
    assert.equal(response.assessment.result, result);
    assert.equal(response.assessment.reasons[0], 'Server reason');
    assert.ok(RESULT_LABELS[result]);
    const call = calls.at(-1);
    assert.equal(call.options.headers.Authorization, 'Bearer test-token');
    assert.equal(call.options.method, 'POST');
    assert.deepEqual(JSON.parse(call.options.body), { answers });
  }
  reply = { status: 200, data: { assessment: null } };
  assert.equal((await eligibilityApi.latest('test-token')).assessment, null);
  assert.ok(calls.at(-1).url.endsWith('/eligibility-assessments/latest'));
  reply = { status: 422, data: { errors: { 'answers.weight': ['Enter a valid weight.'] } } };
  await assert.rejects(eligibilityApi.submit('test-token', answers), /Enter a valid weight/);
  reply = { status: 401, data: {} };
  await assert.rejects(eligibilityApi.latest('test-token'), /session has expired/);
  assert.match(SCREENING_NOTICE, /Final eligibility is confirmed by the blood donation facility/);
  console.log('Frontend API contract checks passed: 3 result states, answer-only payload, bearer token, empty latest, validation and session errors.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
