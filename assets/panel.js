/**
 * headline-options: block editor panel.
 *
 * "Use" edits the title in the editor store only (core/editor editPost). The
 * plugin never saves the post: the post stays dirty until the editor saves.
 */
(function (wp, core, config) {
	'use strict';
	if (!wp || !core || !wp.plugins || !wp.element || !wp.data || !wp.apiFetch) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
	var Button = wp.components.Button;
	var TextControl = wp.components.TextControl;
	var CheckboxControl = wp.components.CheckboxControl;
	var Notice = wp.components.Notice;
	var ns = '/' + (config.namespace || 'headline-options/v1');
	if (!Panel) {
		return;
	}

	function editorState() {
		var sel = wp.data.select('core/editor');
		return {
			id: sel.getCurrentPostId(),
			title: sel.getEditedPostAttribute('title') || '',
			content: sel.getEditedPostContent() || '',
			excerpt: sel.getEditedPostAttribute('excerpt') || ''
		};
	}

	function HeadlineOptionsPanel() {
		var s = editorState();
		var title = wp.data.useSelect(function (select) {
			return select('core/editor').getEditedPostAttribute('title') || '';
		}, []);
		var _status = useState(null), status = _status[0], setStatus = _status[1];
		var _set = useState(null), set = _set[0], setSet = _set[1];
		var _age = useState(''), age = _age[0], setAge = _age[1];
		var _notes = useState([]), notes = _notes[0], setNotes = _notes[1];
		var _err = useState(''), error = _err[0], setError = _err[1];
		var _busy = useState(false), busy = _busy[0], setBusy = _busy[1];
		var _prev = useState([]), previous = _prev[0], setPrevious = _prev[1];
		var _cur = useState(null), currentScore = _cur[0], setCurrentScore = _cur[1];
		var _own = useState(''), own = _own[0], setOwn = _own[1];
		var _ownScore = useState(null), ownScore = _ownScore[0], setOwnScore = _ownScore[1];
		var _confirm = useState(!!config.confirmUse), confirmOn = _confirm[0], setConfirmOn = _confirm[1];
		var titleTimer = useRef(null);
		var ownTimer = useRef(null);

		useEffect(function () {
			wp.apiFetch({ path: ns + '/status' }).then(setStatus).catch(function () {});
			core.sha256Hex(s.content).then(function (hash) {
				return wp.apiFetch({ path: ns + '/posts/' + s.id + '/sets' + (hash ? '?content_hash=' + hash : '') });
			}).then(function (sets) {
				if (Array.isArray(sets) && sets.length) {
					setSet(sets[0]);
					setAge(core.ageLabel(sets[0].created_at, Date.now(), sets[0].stale === true));
				}
			}).catch(function () {});
		}, []);

		function score(headlines, done) {
			var st = editorState();
			wp.apiFetch({ path: ns + '/posts/' + st.id + '/score', method: 'POST', data: { headlines: headlines, content: st.content, current: st.title } })
				.then(done)
				.catch(function (e) { setError(core.errorMessage(e)); });
		}

		useEffect(function () {
			if (title.trim() === '') {
				setCurrentScore(null);
				return;
			}
			clearTimeout(titleTimer.current);
			titleTimer.current = setTimeout(function () {
				score([title], function (r) { setCurrentScore(r[0]); });
			}, config.debounceMs || 400);
		}, [title]);

		function suggest() {
			var st = editorState();
			setBusy(true);
			setError('');
			wp.apiFetch({ path: ns + '/posts/' + st.id + '/generate', method: 'POST', data: { title: st.title, content: st.content, excerpt: st.excerpt } })
				.then(function (r) {
					setSet(r.set);
					setAge('');
					setNotes((r.notes || []).map(function (n) { return n.message; }).concat(r.saved ? [] : [core.MESSAGES.notSaved]));
				})
				.catch(function (e) { setError(core.errorMessage(e)); })
				.then(function () { setBusy(false); });
		}

		function use(row) {
			var result = core.useCandidate(row, {
				getTitle: function () { return editorState().title; },
				setTitle: function (t) { wp.data.dispatch('core/editor').editPost({ title: t }); },
				confirm: function (m) { return window.confirm(m); },
				confirmEnabled: confirmOn,
				previous: previous,
				recordChoice: function (setId, index) {
					return wp.apiFetch({ path: ns + '/posts/' + editorState().id + '/choice', method: 'POST', data: { set_id: setId, index: index } });
				}
			});
			setPrevious(result.previous);
		}

		function onOwn(value) {
			setOwn(value);
			clearTimeout(ownTimer.current);
			if (value.length > 1000) {
				setOwnScore(null);
				setError(core.MESSAGES.tooLong);
				return;
			}
			if (value.trim() === '') {
				setOwnScore(null);
				return;
			}
			ownTimer.current = setTimeout(function () {
				score([value], function (r) { setOwnScore(r[0]); });
			}, config.debounceMs || 400);
		}

		function toggleConfirm(on) {
			setConfirmOn(on);
			wp.apiFetch({ path: '/wp/v2/users/me', method: 'POST', data: { meta: { headline_options_skip_confirm: !on } } }).catch(function () {});
		}

		var enabled = status ? status.enabled : false;
		var reason = status && !status.enabled ? (status.configured ? core.MESSAGES.disabled : core.MESSAGES.notConfigured) : '';
		var rows = core.buildRows(set, title, currentScore, previous);
		var handlers = { use: use, copy: function (row) { core.copyText(row.text).catch(function () {}); } };

		return el(Panel, { name: 'headline-options', title: 'Headline options', className: 'headline-options-panel' },
			el(Button, { variant: 'secondary', isBusy: busy, disabled: busy || !enabled, onClick: suggest }, 'Suggest headlines'),
			reason ? el('p', { className: 'headline-options-reason' }, reason) : null,
			error ? el(Notice, { status: 'error', isDismissible: false }, error) : null,
			age ? el('p', { className: 'headline-options-age' }, age) : null,
			notes.map(function (n, i) { return el('p', { key: 'n' + i, className: 'headline-options-note' }, n); }),
			el('ol', { className: 'headline-options-list' }, rows.map(function (row, i) { return core.renderRow(el, row, handlers, 'row' + i); })),
			el(TextControl, { label: 'Score your own headline', value: own, onChange: onOwn, dir: 'auto' }),
			ownScore ? el('ul', null, core.renderRow(el, { kind: 'own', text: ownScore.headline, score: ownScore }, { use: function () { use({ kind: 'own', text: ownScore.headline }); }, copy: handlers.copy }, 'own')) : null,
			el(CheckboxControl, { label: 'Ask before replacing the headline', checked: confirmOn, onChange: toggleConfirm })
		);
	}

	wp.plugins.registerPlugin('headline-options', { render: HeadlineOptionsPanel, icon: 'editor-textcolor' });
})(window.wp, window.HeadlineOptionsCore, window.headlineOptionsConfig || {});
