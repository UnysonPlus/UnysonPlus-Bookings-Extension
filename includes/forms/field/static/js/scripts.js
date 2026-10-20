fwEvents.on('fw-builder:form-builder:register-items', function (builder) {
	fwFormBuilderSimpleItem(builder, 'booking', {
		preview: function () {
			var cells = '';
			for (var i = 1; i <= 14; i++) { cells += '<span style="display:inline-grid;place-items:center;width:22px;height:22px;margin:1px;border-radius:50%;font-size:11px;' + (i === 4 || i === 5 ? 'text-decoration:line-through;opacity:.4' : (i >= 8 && i <= 11 ? 'background:#dbe7fb' : '')) + '">' + i + '</span>'; }
			return '<div style="display:flex;gap:16px;align-items:flex-start"><div style="max-width:180px">' + cells + '</div>' +
				'<div style="flex:1;font-size:12px;opacity:.75;line-height:1.5">Live calendar: taken dates or times greyed out, the price, guests.</div></div>';
		}
	});
});
