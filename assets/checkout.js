/**
 * Woo Pay – Stellar checkout helpers.
 *
 * Beginners: this file runs in the browser on checkout + thank-you pages.
 * It adds "Copy" buttons and (on thank-you) checks the payment every 30s,
 * showing the customer a clear message when something is wrong.
 */
(function () {
  'use strict';

  function copyText(text, btn) {
    var done = function () {
      var original = btn.textContent;
      var label = (window.WooPayStellar && window.WooPayStellar.copied) || 'Copied!';
      btn.textContent = label;
      setTimeout(function () { btn.textContent = original; }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, done);
    } else {
      var ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e) { /* ignore */ }
      document.body.removeChild(ta);
      done();
    }
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    // Copy wallet address (checkout + thank-you).
    if (t.classList.contains('woo-pay-copy')) {
      var box = t.closest('.woo-pay-stellar-box, .woo-pay-instructions');
      var addr = box ? box.querySelector('.woo-pay-address') : null;
      if (addr) copyText(addr.textContent.trim(), t);
    }

    // Copy memo (thank-you page).
    if (t.classList.contains('woo-pay-copy-memo')) {
      var memo = document.querySelector('.woo-pay-memo');
      if (memo) copyText(memo.textContent.trim(), t);
    }
  });

  // Thank-you page: ask the server every 30s whether the payment arrived and show
  // its answer in the status box, so the customer knows what is wrong and what to do.
  var statusBox = document.querySelector('.woo-pay-status');
  if (!statusBox) return;

  var checkUrl = statusBox.getAttribute('data-check-url');
  var strings = window.WooPayStellar || {};

  // kind: 'info' (still waiting), 'error' (customer must act), 'message' (paid).
  function showStatus(text, kind) {
    statusBox.textContent = text;
    statusBox.className = 'woo-pay-status woocommerce-' + kind;
  }

  // Reasons where waiting longer can still fix it. Anything else needs the customer to act.
  var waitingReasons = { no_payment_found: true, network_error: true };

  function checkAgainLater() {
    setTimeout(checkPayment, 30000);
  }

  function checkPayment() {
    fetch(checkUrl, { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.paid) {
          statusBox.setAttribute('data-state', 'paid');
          showStatus(data.message || strings.paid || 'Payment received. Thank you!', 'message');
          // Reload once so the order details above show the new status.
          setTimeout(function () { window.location.reload(); }, 2000);
          return;
        }

        statusBox.setAttribute('data-state', data.reason || 'pending');
        if (data.message) {
          showStatus(data.message, waitingReasons[data.reason] ? 'info' : 'error');
        }
        // An expired order will not change, so stop asking.
        if (data.reason !== 'expired') checkAgainLater();
      })
      .catch(function () {
        // Our own site did not answer (offline, server error). Say so and keep trying.
        showStatus(strings.checkFailed || 'We could not check your payment just now. We will try again shortly.', 'info');
        checkAgainLater();
      });
  }

  if (!checkUrl || statusBox.getAttribute('data-state') === 'paid') return;

  if (window.fetch) {
    checkPayment();
  } else {
    // Very old browser: fall back to a plain reload.
    setTimeout(function () { window.location.reload(); }, 30000);
  }
})();
