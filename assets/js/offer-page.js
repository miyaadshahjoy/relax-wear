// /astra-child/assets/js/offer-page.js
/**
 * OTO offer page — accept/decline click handling.
 *
 * Vanilla JS, no build step, no framework dependency. Expects
 * `otoOfferData` to be localized via wp_localize_script with:
 *   { ajaxUrl, nonce, i18n: { processing, declineProcessing, alreadyProcessed, genericError } }
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    const container = document.querySelector(".oto-offer");

    // No offer on this page (e.g. an error state was rendered
    // instead) — nothing to wire up.
    if (!container || !container.dataset.otoToken) {
      return;
    }

    const acceptButton = container.querySelector('[data-oto-action="accept"]');
    const declineButton = container.querySelector(
      '[data-oto-action="decline"]',
    );

    if (acceptButton) {
      acceptButton.addEventListener("click", function () {
        handleAction("oto_accept", acceptButton, [acceptButton, declineButton]);
      });
    }

    if (declineButton) {
      declineButton.addEventListener("click", function () {
        handleAction("oto_decline", declineButton, [
          acceptButton,
          declineButton,
        ]);
      });
    }
  });

  /**
   * Sends the accept/decline request and follows the response's
   * redirect_url on success.
   *
   * @param {string}      action        'oto_accept' | 'oto_decline'
   * @param {HTMLElement} clickedButton The button that was clicked — gets the "processing" label.
   * @param {HTMLElement[]} allButtons  Every button to disable while the request is in flight,
   *                                    so a customer can't click Accept and Decline in quick succession.
   */
  function handleAction(action, clickedButton, allButtons) {
    const token = clickedButton.dataset.otoToken;

    if (!token) {
      return;
    }

    setButtonsDisabled(allButtons, true);

    // Save the button's original label for later, so we can restore it if the request fails.
    const originalLabel = clickedButton.textContent;
    // Replace the button's label with the "processing" label.
    clickedButton.textContent = otoOfferData.i18n.processing;
    // info: i18n -> internationalization

    // info: URLSearchParams() -> a native browser API for building URL-encode form data, similar to FormData.
    const body = new URLSearchParams();
    body.set("action", action);
    body.set("nonce", otoOfferData.nonce);
    body.set("oto_token", token);

    fetch(otoOfferData.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body.toString(),
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (json) {
        if (json.success && json.data && json.data.redirect_url) {
          // Full navigation, not a fetch-and-replace — the next step is a full offer page (or the thank-you page), not a fragment this page can render itself.
          window.location.href = json.data.redirect_url;
          return;
        }

        handleFailure(json, clickedButton, allButtons, originalLabel);
      })
      .catch(function () {
        handleFailure(null, clickedButton, allButtons, originalLabel);
      });
  }

  /**
   * Shows an error message and restores button state — except for
   * the "already processed" case (HTTP 409 from the backend),
   * where re-enabling the buttons would just invite another
   * request that's guaranteed to fail the same way again.
   */
  function handleFailure(json, clickedButton, allButtons, originalLabel) {
    const message = otoOfferData.i18n.genericError;
    const isAlreadyProcessed = false;

    if (json && json.data && json.data.message) {
      message = json.data.message;
      isAlreadyProcessed = /already been processed/i.test(json.data.message);
    }

    showMessage(clickedButton, message);

    if (isAlreadyProcessed) {
      // Leave both buttons disabled — nothing left to do here.
      return;
    }

    clickedButton.textContent = originalLabel;
    setButtonsDisabled(allButtons, false);
  }

  function setButtonsDisabled(buttons, disabled) {
    buttons.forEach(function (button) {
      if (button) {
        button.disabled = disabled;
      }
    });
  }

  function showMessage(nearButton, message) {
    const container = nearButton.closest(".oto-offer");

    if (!container) {
      return;
    }

    const messageEl = container.querySelector(".oto-offer__message");

    if (!messageEl) {
      messageEl = document.createElement("p");
      messageEl.className = "oto-offer__message";
      container.appendChild(messageEl);
    }

    messageEl.textContent = message;
  }
})();
