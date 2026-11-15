/**
 * headline-options: reduced classic editor meta box. Suggest, the list with
 * scores, and a Use button that fills the title input. Nothing is saved.
 */
(function (wp, core, config) {
	'use strict';
	var box = document.getElementById('headline-options-classic');
	if (!box || !core || !wp || !wp.apiFetch) {
		return;
	}
	var ns = '/' + (config.namespace || 'headline-options/v1');
	var postId = box.getAttribute('data-post-id');
	var list = box.querySelector('.headline-options-list');
	var message = box.querySelector('.headline-options-message');
	var previous = [];
	var lastSet = null;

	// Minimal createElement for core.renderRow: text nodes only.
	function el(type, props) {
		var node = document.createElement(type);
		props = props || {};
		Object.keys(props).forEach(function (k) {
			if (k === 'onClick') {
				node.addEventListener('click', props[k]);
			} else if (k === 'className') {
				node.className = props[k];
			} else if (k === 'dir' || k === 'type') {
				node.setAttribute(k, props[k]);
			}
		});
		Array.prototype.slice.call(arguments, 2).forEach(function append(child) {
			if (Array.isArray(child)) {
				child.forEach(append);
			} else if (child !== null && child !== undefined) {
				node.appendChild(typeof child === 'string' || typeof child === 'number' ? document.createTextNode(String(child)) : child);
			}
		});
		return node;
	}

	function titleInput() {
		return document.getElementById('title');
	}

	function content() {
		if (window.tinymce && window.tinymce.get('content') && !window.tinymce.get('content').isHidden()) {
			return window.tinymce.get('content').getContent();
		}
		var ta = document.getElementById('content');
		return ta ? ta.value : '';
	}

	function render() {
		list.textContent = '';
		var rows = core.buildRows(lastSet, titleInput() ? titleInput().value : '', null, previous);
		rows.forEach(function (row, i) {
			list.appendChild(core.renderRow(el, row, handlers, 'r' + i));
		});
	}

	var handlers = {
		copy: function (row) { core.copyText(row.text).catch(function () {}); },
		use: function (row) {
			var input = titleInput();
			var result = core.useCandidate(row, {
				getTitle: function () { return input ? input.value : ''; },
				setTitle: function (t) {
					if (input) {
						input.value = t;
						input.dispatchEvent(new Event('input', { bubbles: true }));
					}
				},
				confirm: function (m) { return window.confirm(m); },
				confirmEnabled: !!config.confirmUse,
				previous: previous,
				recordChoice: function (setId, index) {
					return wp.apiFetch({ path: ns + '/posts/' + postId + '/choice', method: 'POST', data: { set_id: setId, index: index } });
				}
			});
			previous = result.previous;
			render();
		}
	};

	box.querySelector('[data-action="suggest"]').addEventListener('click', function (ev) {
		var button = ev.currentTarget;
		button.disabled = true;
		message.textContent = '';
		wp.apiFetch({
			path: ns + '/posts/' + postId + '/generate',
			method: 'POST',
			data: { title: titleInput() ? titleInput().value : '', content: content(), excerpt: (document.getElementById('excerpt') || {}).value || '' }
		}).then(function (r) {
			lastSet = r.set;
			message.textContent = (r.notes || []).map(function (n) { return n.message; }).join(' ');
			render();
		}).catch(function (e) {
			message.textContent = core.errorMessage(e);
		}).then(function () {
			button.disabled = false;
		});
	});

	wp.apiFetch({ path: ns + '/posts/' + postId + '/sets' }).then(function (sets) {
		if (Array.isArray(sets) && sets.length) {
			lastSet = sets[0];
			message.textContent = core.ageLabel(sets[0].created_at, Date.now(), false);
			render();
		}
	}).catch(function () {});
})(window.wp, window.HeadlineOptionsCore, window.headlineOptionsConfig || {});
