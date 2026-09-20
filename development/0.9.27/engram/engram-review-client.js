/* KiCom Engram: first-party WebAuthn review client (DEV, not deployed).
 * Serve as an immutable SAME-ORIGIN static file with CSP script-src 'self'.
 * Never put a private memory, a token, or a browser session in the URL.
 */
(() => {
  "use strict";

  const fromBase64url = (encoded) => {
    if (typeof encoded !== "string" || !/^[A-Za-z0-9_-]+$/.test(encoded)) {
      throw new Error("Ungültige Passkey-Antwort.");
    }
    const standard = encoded.replace(/-/g, "+").replace(/_/g, "/");
    const raw = atob(standard.padEnd(Math.ceil(standard.length / 4) * 4, "="));
    return Uint8Array.from(raw, (character) => character.charCodeAt(0));
  };

  const toBase64url = (bytes) => {
    const array = new Uint8Array(bytes);
    let encoded = "";
    for (let position = 0; position < array.length; position += 8192) {
      encoded += String.fromCharCode(...array.subarray(position, position + 8192));
    }
    return btoa(encoded).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
  };

  const post = async (path, operation, payload) => {
    const response = await fetch(path, {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      redirect: "error",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json"
      },
      body: JSON.stringify({ operation, payload })
    });
    const json = await response.json();
    if (!response.ok || json.ok !== true) {
      throw new Error("Die Freigabe wurde nicht erteilt. Bitte erneut prüfen.");
    }
    return json;
  };

  document.querySelectorAll("form[data-engram-review-api]").forEach((form) => {
    const status = document.getElementById("engram-review-status");
    const button = form.querySelector('button[name="decision"]');
    const csrf = form.querySelector('input[name="csrf"]');
    const review = form.querySelector('input[name="review_id"]');
    if (!status || !button || !csrf || !review) return;

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (button.disabled) return;
      const api = form.getAttribute("data-engram-review-api");
      const target = new URL(api, location.origin);
      if (target.origin !== location.origin || !navigator.credentials ||
          typeof navigator.credentials.get !== "function") {
        status.textContent = "Die Passkey-Freigabe ist in diesem Browser nicht verfügbar.";
        return;
      }

      button.disabled = true;
      status.textContent = "Passkey-Bestätigung wird vorbereitet …";
      try {
        const payload = { review_id: review.value, csrf: csrf.value };
        const begin = await post(target.pathname + target.search, "ENGRAM_REVIEW_BEGIN", payload);
        const publicKey = begin.publicKey;
        if (!publicKey || !Array.isArray(publicKey.allowCredentials) ||
            typeof begin.challenge_id !== "string") {
          throw new Error("Die Passkey-Anfrage ist unvollständig.");
        }
        const request = {
          ...publicKey,
          challenge: fromBase64url(publicKey.challenge),
          allowCredentials: publicKey.allowCredentials.map((credential) => ({
            ...credential,
            id: fromBase64url(credential.id)
          })),
          userVerification: "required"
        };
        status.textContent = "Bitte bestätige diese einzelne Erinnerung mit deinem Passkey.";
        const credential = await navigator.credentials.get({ publicKey: request });
        if (!credential || !credential.response) {
          throw new Error("Keine gültige Passkey-Bestätigung erhalten.");
        }
        const assertion = {
          id: credential.id,
          rawId: toBase64url(credential.rawId),
          response: {
            clientDataJSON: toBase64url(credential.response.clientDataJSON),
            authenticatorData: toBase64url(credential.response.authenticatorData),
            signature: toBase64url(credential.response.signature)
          }
        };
        await post(target.pathname + target.search, "ENGRAM_REVIEW_CONFIRM", {
          ...payload,
          challenge_id: begin.challenge_id,
          assertion
        });
        status.textContent = "Diese eine Erinnerung wurde freigegeben.";
        button.textContent = "Freigabe erteilt";
      } catch (error) {
        status.textContent = error instanceof Error ? error.message :
          "Die Freigabe ist fehlgeschlagen.";
        button.disabled = false;
      }
    });
  });
})();
