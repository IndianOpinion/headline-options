/**
 * headline-options: editor logic shared by the block editor panel and the
 * classic meta box. No framework: rendering takes a createElement-style
 * function, so the same code runs under React (wp.element) and in tests.
 *
 * Candidate text is model output. It is only ever rendered as text nodes.
 */
(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	} else {
		root.HeadlineOptionsCore = api;
	}
})(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	var MESSAGES = {
		confirm: 'Replace the current headline in the editor? It is not saved until you save the post.',
		noHeadline: 'No headline yet',
		disabled: 'Suggestions are switched off by the site administrator.',
		notConfigured: 'No model provider is set up.',
		tooLong: 'The text is too long to score.',
		notSaved: 'These suggestions were not stored and will not reappear when you reopen the post.',
		earlier: 'for an earlier version of the text'
	};

	/**
	 * Rows for the list: the current headline first, then the candidates in
	 * the order the server sorted them (score, then model order), then any
	 * previous headlines kept this session.
	 */
	function buildRows(set, currentTitle, currentScore, previous) {
		var rows = [];
		var title = typeof currentTitle === 'string' ? currentTitle : '';
		rows.push({
			kind: 'current',
			text: title.trim() === '' ? MESSAGES.noHeadline : title,
			empty: title.trim() === '',
			score: title.trim() === '' ? null : currentScore || null
		});
		var candidates = set && Array.isArray(set.candidates) ? set.candidates : [];
		candidates.forEach(function (c, index) {
			rows.push({ kind: 'candidate', text: c.text, angle: c.angle || '', score: c.score || null, index: index, setId: set.id });
		});
		(previous || []).forEach(function (text) {
			if (text !== title) {
				rows.push({ kind: 'previous', text: text, score: null });
			}
		});
		return rows;
	}

	/**
	 * "Use": set the title in the editor state only. Never saves.
	 * deps: getTitle(), setTitle(text), confirm(message) -> bool,
	 *       confirmEnabled (bool), recordChoice(setId, index) -> Promise|undefined,
	 *       previous (array, mutated copy returned).
	 */
	function useCandidate(row, deps) {
		var current = deps.getTitle() || '';
		var previous = (deps.previous || []).slice();
		if (deps.confirmEnabled && current.trim() !== '' && current !== row.text) {
			if (!deps.confirm(MESSAGES.confirm)) {
				return { applied: false, previous: previous };
			}
		}
		deps.setTitle(row.text);
		if (current.trim() !== '' && current !== row.text && previous.indexOf(current) === -1) {
			previous.push(current);
		}
		if (row.kind === 'candidate' && typeof deps.recordChoice === 'function') {
			try {
				var p = deps.recordChoice(row.setId, row.index);
				if (p && typeof p.catch === 'function') {
					p.catch(function () {});
				}
			} catch (e) {
				// A failed record never blocks or reverts the choice.
			}
		}
		return { applied: true, previous: previous };
	}

	function ageLabel(createdAt, now, stale) {
		var then = Date.parse(createdAt);
		var label = 'generated just now';
		if (!isNaN(then)) {
			var minutes = Math.max(0, Math.round((now - then) / 60000));
			if (minutes >= 2880) {
				label = 'generated ' + Math.round(minutes / 1440) + ' days ago';
			} else if (minutes >= 120) {
				label = 'generated ' + Math.round(minutes / 60) + ' hours ago';
			} else if (minutes >= 60) {
				label = 'generated 1 hour ago';
			} else if (minutes >= 2) {
				label = 'generated ' + minutes + ' minutes ago';
			}
		}
		return stale ? label + ' ' + MESSAGES.earlier : label;
	}

	function sha256Hex(text) {
		var c = (typeof globalThis !== 'undefined' && globalThis.crypto) || null;
		if (!c || !c.subtle || typeof TextEncoder === 'undefined') {
			return Promise.resolve('');
		}
		return c.subtle.digest('SHA-256', new TextEncoder().encode(text)).then(function (buf) {
			return Array.prototype.map.call(new Uint8Array(buf), function (b) {
				return ('0' + b.toString(16)).slice(-2);
			}).join('');
		});
	}

	function errorMessage(error) {
		if (!error) {
			return 'Something went wrong.';
		}
		var code = error.code || '';
		if (code === 'headline_options_disabled') {
			return MESSAGES.disabled;
		}
		if (code === 'headline_options_not_configured') {
			return error.message || MESSAGES.notConfigured;
		}
		if (code === 'headline_options_refused') {
			return 'The provider declined this article.';
		}
		return error.message || 'Something went wrong.';
	}

	function scoreLabel(score) {
		if (!score || score.total === null || score.total === undefined) {
			return '';
		}
		return score.total + ' ' + (score.band || '');
	}

	/**
	 * Render one row. Every string goes in as a child text node; nothing is
	 * set as HTML. "Use" is never disabled, whatever the score.
	 */
	function renderRow(el, row, handlers, key) {
		var children = [];
		var label = row.kind === 'current' ? 'Current' : row.kind === 'previous' ? 'Previous' : null;
		if (label) {
			children.push(el('span', { key: 'label', className: 'headline-options-label' }, label));
		}
		children.push(el('span', { key: 'text', className: 'headline-options-text', dir: 'auto' }, row.text));
		if (row.angle) {
			children.push(el('small', { key: 'angle', className: 'headline-options-angle', dir: 'auto' }, row.angle));
		}
		if (row.score) {
			children.push(el('span', { key: 'score', className: 'headline-options-score is-' + (row.score.band || 'none') }, scoreLabel(row.score)));
			children.push(el('span', { key: 'len', className: 'headline-options-length' }, row.score.length + ' chars'));
			var rules = (row.score.rules || []).map(function (r, i) {
				return el('li', { key: 'r' + i, className: 'is-' + r.status }, r.status + ' ' + r.id + ': ' + r.reason);
			});
			children.push(el('details', { key: 'rules' }, el('summary', null, 'Why'), el('ul', null, rules)));
		}
		if (row.kind !== 'current') {
			children.push(el('button', { key: 'copy', type: 'button', className: 'button', onClick: function () { handlers.copy(row); } }, 'Copy'));
			children.push(el('button', { key: 'use', type: 'button', className: 'button button-primary', disabled: false, onClick: function () { handlers.use(row); } }, 'Use'));
		}
		return el('li', { key: key, className: 'headline-options-row is-' + row.kind }, children);
	}

	function copyText(text) {
		var nav = typeof navigator !== 'undefined' ? navigator : null;
		if (nav && nav.clipboard && nav.clipboard.writeText) {
			return nav.clipboard.writeText(text);
		}
		return Promise.reject(new Error('Clipboard not available'));
	}

	return {
		MESSAGES: MESSAGES,
		buildRows: buildRows,
		useCandidate: useCandidate,
		ageLabel: ageLabel,
		sha256Hex: sha256Hex,
		errorMessage: errorMessage,
		scoreLabel: scoreLabel,
		renderRow: renderRow,
		copyText: copyText
	};
});
