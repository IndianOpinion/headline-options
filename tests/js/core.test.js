'use strict';

// Editor tests: T26, T27, T28, T30 (age label), T47, T64 (text nodes), T65.
// Run with: node --test tests/js
const test = require('node:test');
const assert = require('node:assert');
const core = require('../../assets/core.js');

function el(type, props, ...children) {
	return { type, props: props || {}, children: children.flat(Infinity).filter((c) => c !== null && c !== undefined) };
}

function find(node, pred, out = []) {
	if (node && typeof node === 'object') {
		if (pred(node)) {
			out.push(node);
		}
		(node.children || []).forEach((c) => find(c, pred, out));
	}
	return out;
}

const set = {
	id: 'set-1',
	candidates: [
		{ text: 'Reserve Bank holds repo rate at 6.5%', angle: 'the number', score: { total: 90, band: 'good', length: 36, rules: [{ id: 'length', status: 'pass', reason: 'ok' }] } },
		{ text: 'You will not believe this!!', angle: '', score: { total: 12, band: 'weak', length: 27, rules: [] } }
	]
};

function editor(title) {
	const state = { title, saves: 0, edits: [], confirms: 0, records: [] };
	return {
		state,
		deps(extra) {
			return Object.assign({
				getTitle: () => state.title,
				setTitle: (t) => { state.edits.push({ title: t }); state.title = t; },
				savePost: () => { state.saves++; },
				confirm: () => { state.confirms++; return true; },
				confirmEnabled: true,
				previous: [],
				recordChoice: (id, i) => { state.records.push([id, i]); return Promise.resolve({ recorded: true }); }
			}, extra || {});
		}
	};
}

test('T26 Use dispatches a title edit and never saves', () => {
	const e = editor('');
	const rows = core.buildRows(set, '', null, []);
	const r = core.useCandidate(rows[1], e.deps());
	assert.strictEqual(r.applied, true);
	assert.deepStrictEqual(e.state.edits, [{ title: 'Reserve Bank holds repo rate at 6.5%' }]);
	assert.strictEqual(e.state.saves, 0);
	assert.deepStrictEqual(e.state.records, [['set-1', 0]]);
});

test('T27 Use over a different non-empty headline asks first; off skips it; refusal changes nothing', () => {
	const e = editor('Repo rate unchanged');
	const rows = core.buildRows(set, 'Repo rate unchanged', null, []);
	core.useCandidate(rows[1], e.deps());
	assert.strictEqual(e.state.confirms, 1);

	const e2 = editor('Repo rate unchanged');
	core.useCandidate(rows[1], e2.deps({ confirmEnabled: false }));
	assert.strictEqual(e2.state.confirms, 0);
	assert.strictEqual(e2.state.title, rows[1].text);

	const e3 = editor('Repo rate unchanged');
	const r3 = core.useCandidate(rows[1], e3.deps({ confirm: () => false }));
	assert.strictEqual(r3.applied, false);
	assert.strictEqual(e3.state.title, 'Repo rate unchanged');
	assert.deepStrictEqual(e3.state.records, []);
	assert.match(core.MESSAGES.confirm, /not saved until you save the post/);
});

test('T28 Previous headline is listed after Use and can be restored', () => {
	const e = editor('Repo rate unchanged');
	const rows = core.buildRows(set, 'Repo rate unchanged', null, []);
	const r = core.useCandidate(rows[1], e.deps({ confirmEnabled: false }));
	assert.deepStrictEqual(r.previous, ['Repo rate unchanged']);
	const after = core.buildRows(set, e.state.title, null, r.previous);
	const prev = after.find((row) => row.kind === 'previous');
	assert.strictEqual(prev.text, 'Repo rate unchanged');
	core.useCandidate(prev, e.deps({ confirmEnabled: false, previous: r.previous }));
	assert.strictEqual(e.state.title, 'Repo rate unchanged');
	assert.strictEqual(e.state.saves, 0);
});

test('A failed choice record never blocks or reverts the choice', async () => {
	const e = editor('');
	const rows = core.buildRows(set, '', null, []);
	const r = core.useCandidate(rows[1], e.deps({ recordChoice: () => Promise.reject(new Error('offline')) }));
	assert.strictEqual(r.applied, true);
	const r2 = core.useCandidate(rows[2], e.deps({ recordChoice: () => { throw new Error('boom'); } }));
	assert.strictEqual(r2.applied, true);
	await new Promise((res) => setImmediate(res));
});

test('T30 age label says earlier version when stale', () => {
	const now = Date.parse('2026-11-15T13:00:00Z');
	assert.strictEqual(core.ageLabel('2026-11-15T10:00:00Z', now, true), 'generated 3 hours ago for an earlier version of the text');
	assert.strictEqual(core.ageLabel('2026-11-15T12:55:00Z', now, false), 'generated 5 minutes ago');
});

test('T47 lowest-scoring candidate still has an enabled Use button', () => {
	const rows = core.buildRows(set, 'Repo', null, []);
	const weak = rows.find((r) => r.score && r.score.band === 'weak');
	const tree = core.renderRow(el, weak, { use() {}, copy() {} }, 'k');
	const use = find(tree, (n) => n.type === 'button' && n.children[0] === 'Use');
	assert.strictEqual(use.length, 1);
	assert.strictEqual(use[0].props.disabled, false);
});

test('T25 current headline first; empty current shows No headline yet with no score', () => {
	const rows = core.buildRows(set, '', { total: 50 }, []);
	assert.strictEqual(rows[0].kind, 'current');
	assert.strictEqual(rows[0].text, 'No headline yet');
	assert.strictEqual(rows[0].score, null);
	assert.deepStrictEqual(rows.slice(1).map((r) => r.index), [0, 1]);
});

test('T64 T65 text rendered as text nodes with dir=auto', () => {
	const row = { kind: 'candidate', text: '<img src=x onerror=alert(1)> البنك', angle: 'angle', score: null };
	const tree = core.renderRow(el, row, { use() {}, copy() {} }, 'k');
	const text = find(tree, (n) => n.props && n.props.className === 'headline-options-text')[0];
	assert.strictEqual(text.props.dir, 'auto');
	assert.strictEqual(text.children[0], row.text);
	assert.strictEqual(JSON.stringify(tree).includes('dangerouslySetInnerHTML'), false);
});

test('error messages map codes', () => {
	assert.strictEqual(core.errorMessage({ code: 'headline_options_disabled' }), 'Suggestions are switched off by the site administrator.');
	assert.strictEqual(core.errorMessage({ code: 'headline_options_too_short', message: 'Write a little more of the article first.' }), 'Write a little more of the article first.');
});

test('sha256Hex matches PHP hash of the same content', async () => {
	assert.strictEqual(await core.sha256Hex('abc'), 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
});
