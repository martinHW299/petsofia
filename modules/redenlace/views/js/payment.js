document.addEventListener('DOMContentLoaded', function () {
    var countdown = document.getElementById('redenlace-qr-countdown');
    var expiredMessage = document.getElementById('redenlace-qr-expired');
    var payment = document.getElementById('redenlace-payment');
    var statusMessage = document.getElementById('redenlace-payment-status');
    var qrImage = document.getElementById('redenlace-qr-image');
    var expiry = document.getElementById('redenlace-qr-expiry');
    var waiting = document.getElementById('redenlace-qr-waiting');

    if (!countdown) {
        return;
    }

    var expiresAt = parseInt(countdown.getAttribute('data-expires-at'), 10);
    if (isNaN(expiresAt)) {
        return;
    }

    var timer = null;
    var statusTimer = null;
    var finished = false;

    var showStatus = function (status) {
        var messages = {
            PAGADO: 'Pago confirmado. Serás redirigido a la confirmación de tu pedido.',
            CANCELADO: 'El pago fue cancelado. Puedes volver al checkout para intentar nuevamente.',
            EXPIRADO: 'El código QR venció. Puedes volver al checkout para generar un nuevo código.',
            ERROR: 'No fue posible confirmar el pago. Revisa tu pedido o intenta nuevamente.'
        };
        if (!messages[status]) {
            return;
        }

        finished = true;
        payment.className = payment.className.replace(/\s*redenlace-payment--\S+/g, '') + ' redenlace-payment--' + status.toLowerCase();
        statusMessage.textContent = messages[status];
        statusMessage.hidden = false;
        if (qrImage) {
            qrImage.hidden = true;
        }
        if (expiry) {
            expiry.hidden = true;
        }
        if (waiting) {
            waiting.hidden = true;
        }
        if (expiredMessage) {
            expiredMessage.hidden = true;
        }
        if (timer !== null) {
            window.clearInterval(timer);
        }
        if (statusTimer !== null) {
            window.clearInterval(statusTimer);
        }

        if (status === 'PAGADO') {
            window.setTimeout(function () {
                window.location.assign(statusMessage.getAttribute('data-confirmation-url'));
            }, 1500);
        }
    };

    var fetchStatus = function () {
        var statusUrl = statusMessage ? statusMessage.getAttribute('data-status-url') : '';
        if (finished || !statusUrl || !window.fetch) {
            return;
        }
        window.fetch(statusUrl, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Unable to retrieve payment status.');
            }
            return response.json();
        }).then(function (result) {
            if (result && typeof result.status === 'string') {
                showStatus(result.status);
            }
        }).catch(function () {
            // Keep the payment screen usable. The next polling attempt may succeed.
        });
    };

    var render = function () {
        var remaining = Math.max(0, Math.ceil(expiresAt - (Date.now() / 1000)));
        var hours = Math.floor(remaining / 3600);
        var minutes = Math.floor((remaining % 3600) / 60);
        var seconds = remaining % 60;
        var pad = function (value) {
            return value < 10 ? '0' + value : String(value);
        };

        countdown.textContent = pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
        if (remaining === 0) {
            if (expiredMessage) {
                expiredMessage.hidden = false;
            }
        }
    };

    render();
    timer = window.setInterval(render, 1000);
    fetchStatus();
    statusTimer = window.setInterval(fetchStatus, 4000);
});
