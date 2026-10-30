document.addEventListener('submit', function (e) {
	var form = e.target.closest('.ivb-as-form');
	if (!form) { return; }
	e.preventDefault();
	var msg = form.querySelector('.ivb-as-msg');
	var btn = form.querySelector('button');
	var data = new FormData();
	data.append('action', 'ivb_aviso_stock');
	data.append('nonce', ivbAvisoStock.nonce);
	data.append('product_id', form.dataset.product);
	data.append('email', form.querySelector('[name=email]').value);
	data.append('website', form.querySelector('[name=website]').value);
	btn.disabled = true;
	fetch(ivbAvisoStock.ajax, { method: 'POST', body: data, credentials: 'same-origin' })
		.then(function (r) { return r.json(); })
		.then(function (res) {
			msg.textContent = (res.data && res.data.message) || '';
			msg.className = 'ivb-as-msg ' + (res.success ? 'ok' : 'err');
			if (res.success) { form.querySelector('.ivb-as-row').style.display = 'none'; } else { btn.disabled = false; }
		})
		.catch(function () { msg.textContent = 'Error de conexión.'; msg.className = 'ivb-as-msg err'; btn.disabled = false; });
});
