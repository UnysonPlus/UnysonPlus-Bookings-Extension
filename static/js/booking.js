/*
 * Bookings: the "Dates and availability" field's calendar.
 *
 * Mount: <div class="fw-bk" data-fw-bk="{config json}"></div>, next to a [data-fw-bk-plain] box with the
 * posted controls (nights: start / end / guests, slots: date / time / guests). Without JavaScript those
 * plain controls are the field; with it they are hidden and kept in step with the calendar.
 *
 *   nights  pick a check-in day, then a check-out day; taken nights are struck through; the price
 *           (nightly rates with seasons, cleaning fee, deposit) is worked out live, the same way the
 *           server does it (FW_Bookings_Availability::quote_nights).
 *   slots   days with a free time are open; pick a day, then a time.
 *
 * Availability comes from GET /wp-json/fw-bookings/v1/availability and is fetched again after the
 * form is sent or refused, so the calendar never shows a place someone has just taken.
 */
(function () {
	'use strict';

	var DAY = 86400000;

	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
	function parse(s) { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
	function addDays(d, n) { return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); }
	function nightsBetween(a, b) { return Math.round((b - a) / DAY); }
	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function fill(t, v) { return String(t).replace(/%d|%s/, v); }

	function Widget(root) {
		var cfg = {};
		try { cfg = JSON.parse(root.getAttribute('data-fw-bk') || '{}'); } catch (e) {}
		this.root = root;
		this.cfg = cfg;
		this.t = cfg.i18n || {};
		this.plain = root.parentNode ? root.parentNode.querySelector('[data-fw-bk-plain]') : null;
		this.full = {};
		this.days = {};
		this.loaded = {};
		this.today = parse(cfg.today);
		this.view = new Date(this.today.getFullYear(), this.today.getMonth(), 1);
		this.a = null; this.b = null; this.hover = null; this.time = '';
		this.build();
		this.bindPlain();
		this.render();
		this.fetch();
	}

	Widget.prototype.money = function (n) {
		var m = this.cfg.money || { symbol: '$', decimals: 2 };
		var dec = m.decimals && Math.abs(n - Math.round(n)) > 0.0001 ? 2 : 0;
		return (n < 0 ? '-' : '') + m.symbol + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
	};

	Widget.prototype.rateFor = function (d) {
		var md = pad(d.getMonth() + 1) + '-' + pad(d.getDate()), list = this.cfg.seasons || [];
		for (var i = 0; i < list.length; i++) {
			var s = list[i], inside = s.from <= s.to ? (md >= s.from && md <= s.to) : (md >= s.from || md <= s.to);
			if (inside) { return { rate: +s.rate, name: s.name }; }
		}
		return { rate: +this.cfg.baseRate, name: '' };
	};

	Widget.prototype.slots = function () { return this.cfg.mode === 'slots'; };

	Widget.prototype.build = function () {
		var c = this.cfg, t = this.t, g = '';
		for (var i = 1; i <= c.maxGuests; i++) { g += '<option value="' + i + '">' + i + '</option>'; }
		this.root.innerHTML =
			'<div class="fw-bk__grid">' +
				'<div class="fw-bk__cal">' +
					'<div class="fw-bk__head"><button type="button" class="fw-bk__nav" data-nav="-1" aria-label="' + esc(t.prev) + '">&#8249;</button>' +
					'<p class="fw-bk__hint" aria-live="polite"></p>' +
					'<button type="button" class="fw-bk__nav" data-nav="1" aria-label="' + esc(t.next) + '">&#8250;</button></div>' +
					'<div class="fw-bk__months"></div>' +
					(this.slots() ? '<div class="fw-bk__times" aria-live="polite"></div>' : '') +
				'</div>' +
				'<div class="fw-bk__side">' +
					(this.slots() ? '<div class="fw-bk__dates fw-bk__dates--one"><div><span>' + esc(t.when) + '</span><strong data-out="a"></strong></div></div>'
						: '<div class="fw-bk__dates"><div><span>' + esc(t.checkin) + '</span><strong data-out="a"></strong></div><div><span>' + esc(t.checkout) + '</span><strong data-out="b"></strong></div></div>') +
					'<label class="fw-bk__guests"><span>' + esc(t.guests) + '</span><select>' + g + '</select></label>' +
					'<div class="fw-bk__quote" aria-live="polite"></div>' +
				'</div>' +
			'</div>';
		this.$months = this.root.querySelector('.fw-bk__months');
		this.$times = this.root.querySelector('.fw-bk__times');
		this.$hint = this.root.querySelector('.fw-bk__hint');
		this.$quote = this.root.querySelector('.fw-bk__quote');
		this.$a = this.root.querySelector('[data-out="a"]');
		this.$b = this.root.querySelector('[data-out="b"]');
		this.$guests = this.root.querySelector('.fw-bk__guests select');
		var self = this;
		this.root.addEventListener('click', function (e) {
			var nav = e.target.closest('[data-nav]');
			if (nav) { self.shift(+nav.getAttribute('data-nav')); return; }
			var day = e.target.closest('[data-day]');
			if (day && !day.disabled) { self.pick(parse(day.getAttribute('data-day'))); return; }
			var tm = e.target.closest('[data-time]');
			if (tm && !tm.disabled) { self.time = tm.getAttribute('data-time'); self.render(); return; }
			if (e.target.closest('[data-reset]')) { self.a = self.b = null; self.time = ''; self.render(); }
		});
		this.$months.addEventListener('mouseover', function (e) {
			var day = e.target.closest('[data-day]');
			if (!self.slots() && self.a && !self.b && day) { self.hover = parse(day.getAttribute('data-day')); self.paint(); }
		});
		this.$guests.addEventListener('change', function () { self.renderSide(); });
	};

	// The posted controls: take over their values (a refused submission, an edit, a pre-fill), then hide them.
	Widget.prototype.bindPlain = function () {
		var p = this.plain, self = this;
		if (!p) { return; }
		this.$p = {
			a: p.querySelector('[data-bk-a]'), b: p.querySelector('[data-bk-b]'),
			time: p.querySelector('[data-bk-time]'), guests: p.querySelector('[data-bk-guests]')
		};
		if (this.$p.guests && this.$p.guests.value) { this.$guests.value = this.$p.guests.value; }
		if (this.$p.a && this.$p.a.value) {
			var a = parse(this.$p.a.value);
			if (a >= this.today) {
				this.a = a;
				this.view = new Date(a.getFullYear(), a.getMonth(), 1);
				if (this.$p.b && this.$p.b.value && parse(this.$p.b.value) > a) { this.b = parse(this.$p.b.value); }
				if (this.$p.time && this.$p.time.value) { this.time = this.$p.time.value; }
			}
		}
		[this.$p.a, this.$p.b, this.$p.time].forEach(function (el) { if (el) { el.removeAttribute('required'); } });
		if (this.$p.time && this.$p.time.tagName === 'SELECT') {
			var h = document.createElement('input'); h.type = 'hidden'; h.name = this.$p.time.name; h.setAttribute('data-bk-time', '');
			this.$p.time.parentNode.replaceChild(h, this.$p.time); this.$p.time = h;
		}
		if (this.$p.a) { this.$p.a.type = 'hidden'; }
		if (this.$p.b) { this.$p.b.type = 'hidden'; }
		p.hidden = true;
		var form = this.root.closest('form');
		if (form) {
			form.addEventListener('fw-forms:sent', function () { self.a = self.b = null; self.time = ''; self.loaded = {}; self.render(); self.fetch(); });
			form.addEventListener('fw-forms:errors', function () { self.loaded = {}; self.fetch(); });
		}
	};

	Widget.prototype.sync = function () {
		if (!this.$p) { return; }
		var ok = this.slots() ? (this.a && this.time) : (this.a && this.b);
		var va = ok ? ymd(this.a) : '', vb = ok && !this.slots() ? ymd(this.b) : '', vt = ok && this.slots() ? this.time : '';
		var changed = false;
		if (this.$p.a && this.$p.a.value !== va) { this.$p.a.value = va; changed = true; }
		if (this.$p.b && this.$p.b.value !== vb) { this.$p.b.value = vb; changed = true; }
		if (this.$p.time && this.$p.time.value !== vt) { this.$p.time.value = vt; changed = true; }
		if (this.$p.guests && this.$p.guests.value !== this.$guests.value) { this.$p.guests.value = this.$guests.value; changed = true; }
		if (changed && this.$p.a) { this.$p.a.dispatchEvent(new Event('change', { bubbles: true })); }
	};

	Widget.prototype.range = function () {
		var n = Math.max(1, +this.cfg.months || 1);
		var from = this.view < this.today ? this.today : this.view;
		var to = new Date(this.view.getFullYear(), this.view.getMonth() + n, 0);
		return [ymd(from), ymd(to)];
	};

	Widget.prototype.fetch = function () {
		var self = this, c = this.cfg, r;
		if (this.slots()) {
			r = this.range();
			var key = r[0] + '|' + r[1];
			if (this.loaded[key]) { return; }
			this.loaded[key] = true;
		} else {
			if (this.loaded.all) { return; }
			this.loaded.all = true;
			r = [c.today, ymd(addDays(this.today, (+c.horizon || 365) + 1))];
		}
		var url = c.url + (c.url.indexOf('?') === -1 ? '?' : '&') + 'bookable=' + c.bookable + '&from=' + r[0] + '&to=' + r[1];
		this.root.classList.add('is-loading');
		fetch(url, { credentials: 'same-origin' }).then(function (res) { return res.ok ? res.json() : null; }).then(function (j) {
			self.root.classList.remove('is-loading');
			if (!j) { return; }
			if (j.mode === 'slots') {
				for (var d in j.days) { if (Object.prototype.hasOwnProperty.call(j.days, d)) { self.days[d] = j.days[d]; } }
				// Days in the window with no slot at all are closed: forget older answers for them.
				for (var x = parse(r[0]); x <= parse(r[1]); x = addDays(x, 1)) { if (!j.days[ymd(x)]) { self.days[ymd(x)] = []; } }
				if (self.a && self.time && !self.slotLeft(ymd(self.a), self.time)) { self.time = ''; self.flash(self.t.slotGone); }
			} else {
				self.full = {};
				(j.full || []).forEach(function (n) { self.full[n] = true; });
				if (self.a && (self.full[ymd(self.a)] || (self.b && !self.free(self.a, self.b)))) { self.a = self.b = null; self.flash(self.t.taken); }
			}
			self.render();
		}).catch(function () { self.root.classList.remove('is-loading'); });
	};

	Widget.prototype.slotLeft = function (day, t) {
		var list = this.days[day] || [];
		for (var i = 0; i < list.length; i++) { if (list[i].t === t) { return list[i].left; } }
		return 0;
	};

	Widget.prototype.dayOpen = function (d) {
		var list = this.days[ymd(d)];
		if (!list) { return null; } // not loaded yet
		for (var i = 0; i < list.length; i++) { if (list[i].left > 0) { return true; } }
		return false;
	};

	Widget.prototype.free = function (a, b) {
		for (var d = a; d < b; d = addDays(d, 1)) { if (this.full[ymd(d)]) { return false; } }
		return true;
	};

	Widget.prototype.shift = function (n) {
		var v = new Date(this.view.getFullYear(), this.view.getMonth() + n, 1);
		if (v < new Date(this.today.getFullYear(), this.today.getMonth(), 1)) { return; }
		this.view = v;
		this.renderMonths();
		if (this.slots()) { this.fetch(); }
	};

	Widget.prototype.pick = function (d) {
		var c = this.cfg, t = this.t;
		if (this.slots()) { this.a = d; this.time = ''; this.render(); return; }
		if (!this.a || this.b || d <= this.a) {
			if (this.full[ymd(d)]) { return; }
			this.a = d; this.b = null; this.hover = null;
		} else {
			if (!this.free(this.a, d)) { this.flash(t.runsInto); return; }
			var n = nightsBetween(this.a, d);
			if (n < c.minNights) { this.flash(fill(t.minStay, c.minNights)); return; }
			if (n > c.maxNights) { this.flash(fill(t.maxStay, c.maxNights)); return; }
			this.b = d;
		}
		this.render();
	};

	Widget.prototype.flash = function (msg) {
		var h = this.$hint, self = this;
		h.textContent = msg; h.classList.add('is-warn');
		clearTimeout(this._t);
		this._t = setTimeout(function () { h.classList.remove('is-warn'); self.hint(); }, 3500);
	};

	Widget.prototype.hint = function () {
		var t = this.t, c = this.cfg;
		if (this.slots()) { this.$hint.textContent = !this.a ? t.pickDay : (!this.time ? t.pickTime : t.ready); return; }
		this.$hint.textContent = !this.a ? t.pickIn : (!this.b ? fill(t.pickOut, c.minNights) : fill(t.nightsChosen, nightsBetween(this.a, this.b)));
	};

	Widget.prototype.render = function () { this.renderMonths(); this.renderTimes(); this.renderSide(); };

	Widget.prototype.renderMonths = function () {
		var t = this.t, html = '', n = Math.max(1, +this.cfg.months || 1), horizon = addDays(this.today, +this.cfg.horizon || 365);
		for (var m = 0; m < n; m++) {
			var first = new Date(this.view.getFullYear(), this.view.getMonth() + m, 1);
			var days = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
			var lead = (first.getDay() + 6) % 7;
			html += '<div class="fw-bk__month' + (m ? ' fw-bk__month--extra' : '') + '"><h4>' + esc(t.months[first.getMonth()]) + ' ' + first.getFullYear() + '</h4><div class="fw-bk__dow">';
			for (var w = 0; w < 7; w++) { html += '<span>' + esc(t.dow[w]) + '</span>'; }
			html += '</div><div class="fw-bk__days">';
			for (var i = 0; i < lead; i++) { html += '<span></span>'; }
			for (var day = 1; day <= days; day++) {
				var d = new Date(first.getFullYear(), first.getMonth(), day), past = d < this.today || d > horizon, cls = 'fw-bk__day', label = d.getDate() + ' ' + t.months[d.getMonth()] + ' ' + d.getFullYear(), off = past;
				if (this.slots()) {
					var open = this.dayOpen(d);
					if (open === false) { cls += ' is-taken'; off = true; label += ', ' + t.noTimes; }
					if (open === null && !past) { cls += ' is-unknown'; }
				} else {
					var taken = !!this.full[ymd(d)], r = this.rateFor(d);
					if (taken) { cls += ' is-taken'; label += ', ' + t.booked; } else if (!past) { label += ', ' + fill(t.perNight, this.money(r.rate)); }
					if (r.rate > +this.cfg.baseRate) { cls += ' is-peak'; }
				}
				if (past) { cls += ' is-past'; }
				html += '<button type="button" class="' + cls + '" data-day="' + ymd(d) + '"' + (off ? ' disabled' : '') + ' aria-label="' + esc(label) + '">' + day + '</button>';
			}
			html += '</div></div>';
		}
		this.$months.innerHTML = html;
		this.paint();
		this.hint();
	};

	Widget.prototype.paint = function () {
		var a = this.a, b = this.slots() ? null : (this.b || this.hover), btns = this.$months.querySelectorAll('[data-day]');
		for (var i = 0; i < btns.length; i++) {
			var el = btns[i], d = parse(el.getAttribute('data-day'));
			var isA = a && +d === +a, isB = !this.slots() && this.b && +d === +this.b, mid = a && b && d > a && d < b;
			el.classList.toggle('is-start', !!isA);
			el.classList.toggle('is-end', !!isB);
			el.classList.toggle('is-mid', !!mid);
			el.classList.toggle('is-preview', !!(mid && !this.b));
			el.setAttribute('aria-pressed', isA || isB ? 'true' : 'false');
			// While choosing check-out, a taken night after check-in can still be the check-out day.
			if (!this.slots() && !el.classList.contains('is-past')) { el.disabled = el.classList.contains('is-taken') && !(a && !this.b && d > a); }
		}
	};

	Widget.prototype.renderTimes = function () {
		if (!this.$times) { return; }
		var t = this.t, c = this.cfg;
		if (!this.a) { this.$times.innerHTML = ''; return; }
		var list = this.days[ymd(this.a)] || [], html = '';
		list.forEach(function (s) {
			var on = s.t === this.time, full = s.left < 1;
			html += '<button type="button" class="fw-bk__time' + (on ? ' is-on' : '') + '" data-time="' + esc(s.t) + '"' + (full ? ' disabled' : '') + ' aria-pressed="' + (on ? 'true' : 'false') + '">' + esc(s.t) +
				(full ? ' <small>' + esc(t.full) + '</small>' : (c.units > 1 ? ' <small>' + esc(fill(t.left, s.left)) + '</small>' : '')) + '</button>';
		}, this);
		this.$times.innerHTML = list.length ? '<p class="fw-bk__times-title">' + esc(t.times) + '</p><div class="fw-bk__time-list">' + html + '</div>' : '<p class="fw-bk__times-title">' + esc(t.noTimes) + '</p>';
	};

	Widget.prototype.quote = function () {
		var c = this.cfg;
		if (this.slots()) {
			if (!this.a || !this.time) { return null; }
			var total = c.perPerson ? c.slotPrice * (+this.$guests.value || 1) : c.slotPrice;
			return { slot: true, total: total };
		}
		if (!this.a || !this.b) { return null; }
		var groups = {}, order = [], sub = 0;
		for (var d = this.a; d < this.b; d = addDays(d, 1)) {
			var r = this.rateFor(d), k = r.name + '|' + r.rate;
			if (!groups[k]) { groups[k] = { name: r.name, rate: r.rate, n: 0 }; order.push(k); }
			groups[k].n++; sub += r.rate;
		}
		return { lines: order.map(function (k) { return groups[k]; }), subtotal: sub, cleaning: +c.cleaning, total: sub + (+c.cleaning), deposit: +c.deposit };
	};

	Widget.prototype.date = function (d) { return this.t.dowLong[(d.getDay() + 6) % 7] + ' ' + d.getDate() + ' ' + this.t.months[d.getMonth()].slice(0, 3) + ' ' + d.getFullYear(); };

	Widget.prototype.renderSide = function () {
		var c = this.cfg, t = this.t, q = this.quote(), self = this;
		this.$a.textContent = this.a ? this.date(this.a) + (this.slots() && this.time ? ', ' + this.time : '') : t.select;
		if (this.$b) { this.$b.textContent = this.b ? this.date(this.b) : t.select; }
		if (!q) {
			var from = this.slots() ? (c.slotPrice > 0 ? fill(c.perPerson ? t.fromPerPerson : t.fromSlot, this.money(c.slotPrice)) : '') : fill(t.fromNight, this.money(c.baseRate)) + (c.minNights > 1 ? ' ' + fill(t.minNightsNote, c.minNights) : '');
			this.$quote.innerHTML = from ? '<p class="fw-bk__from">' + esc(from) + '</p>' : '';
		} else if (q.slot) {
			this.$quote.innerHTML = q.total > 0 ? '<table class="fw-bk__table"><tbody><tr class="fw-bk__total"><th scope="row">' + esc(t.total) + '</th><td>' + esc(this.money(q.total)) + '</td></tr></tbody></table>' : '';
		} else {
			var rows = q.lines.map(function (l) {
				return '<tr><th scope="row">' + esc(fill(l.n === 1 ? t.night : t.nights, l.n)) + ' &times; ' + esc(self.money(l.rate)) + (l.name ? ' <small>' + esc(l.name) + '</small>' : '') + '</th><td>' + esc(self.money(l.n * l.rate)) + '</td></tr>';
			}).join('');
			this.$quote.innerHTML = '<table class="fw-bk__table"><tbody>' + rows +
				(q.cleaning > 0 ? '<tr><th scope="row">' + esc(t.cleaning) + '</th><td>' + esc(this.money(q.cleaning)) + '</td></tr>' : '') +
				'<tr class="fw-bk__total"><th scope="row">' + esc(t.total) + '</th><td>' + esc(this.money(q.total)) + '</td></tr></tbody></table>' +
				(q.deposit > 0 ? '<p class="fw-bk__small">' + esc(fill(t.deposit, this.money(q.deposit))) + '</p>' : '') +
				'<p class="fw-bk__small"><button type="button" class="fw-bk__link" data-reset>' + esc(t.clear) + '</button></p>';
		}
		this.sync();
	};

	function boot() {
		var els = document.querySelectorAll('.fw-bk[data-fw-bk]');
		for (var i = 0; i < els.length; i++) { if (!els[i].__fwbk) { els[i].__fwbk = new Widget(els[i]); } }
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
	window.fwBookings = { boot: boot };
})();
