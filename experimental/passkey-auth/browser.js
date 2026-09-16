'use strict';

/**
 * Browser-side WebAuthn codec for the KiCom Passkey Auth Bridge.
 * No network calls are made here; the surrounding KiCom UI owns transport.
 */
window.KiComPasskey = (() => {
  const b64uToBytes = (value) => {
    if (typeof value !== 'string' || value.length === 0) throw new Error('base64url value required');
    const pad = '='.repeat((4 - (value.length % 4)) % 4);
    const b64 = (value + pad).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(b64);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  };

  const bytesToB64u = (value) => {
    const bytes = value instanceof ArrayBuffer
      ? new Uint8Array(value)
      : new Uint8Array(value.buffer, value.byteOffset, value.byteLength);
    let raw = '';
    for (const b of bytes) raw += String.fromCharCode(b);
    return btoa(raw).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
  };

  const registrationOptions = (serverPublicKey) => {
    const p = structuredClone(serverPublicKey);
    p.challenge = b64uToBytes(p.challenge);
    p.user.id = b64uToBytes(p.user.id);
    if (Array.isArray(p.excludeCredentials)) {
      p.excludeCredentials = p.excludeCredentials.map((c) => ({ ...c, id: b64uToBytes(c.id) }));
    }
    return p;
  };

  const assertionOptions = (serverPublicKey) => {
    const p = structuredClone(serverPublicKey);
    p.challenge = b64uToBytes(p.challenge);
    if (Array.isArray(p.allowCredentials)) {
      p.allowCredentials = p.allowCredentials.map((c) => ({ ...c, id: b64uToBytes(c.id) }));
    }
    return p;
  };

  const serializeRegistration = (credential) => ({
    id: credential.id,
    rawId: bytesToB64u(credential.rawId),
    type: credential.type,
    authenticatorAttachment: credential.authenticatorAttachment || null,
    response: {
      clientDataJSON: bytesToB64u(credential.response.clientDataJSON),
      attestationObject: bytesToB64u(credential.response.attestationObject),
      transports: typeof credential.response.getTransports === 'function'
        ? credential.response.getTransports()
        : [],
    },
    clientExtensionResults: credential.getClientExtensionResults(),
  });

  const serializeAssertion = (credential) => ({
    id: credential.id,
    rawId: bytesToB64u(credential.rawId),
    type: credential.type,
    authenticatorAttachment: credential.authenticatorAttachment || null,
    response: {
      clientDataJSON: bytesToB64u(credential.response.clientDataJSON),
      authenticatorData: bytesToB64u(credential.response.authenticatorData),
      signature: bytesToB64u(credential.response.signature),
      userHandle: credential.response.userHandle ? bytesToB64u(credential.response.userHandle) : null,
    },
    clientExtensionResults: credential.getClientExtensionResults(),
  });

  const enroll = async (serverPublicKey) => {
    if (!window.PublicKeyCredential || !navigator.credentials) throw new Error('WebAuthn unavailable');
    const credential = await navigator.credentials.create({ publicKey: registrationOptions(serverPublicKey) });
    if (!credential) throw new Error('Passkey enrollment cancelled');
    return serializeRegistration(credential);
  };

  const approve = async (serverPublicKey) => {
    if (!window.PublicKeyCredential || !navigator.credentials) throw new Error('WebAuthn unavailable');
    const credential = await navigator.credentials.get({ publicKey: assertionOptions(serverPublicKey) });
    if (!credential) throw new Error('Passkey approval cancelled');
    return serializeAssertion(credential);
  };

  return { b64uToBytes, bytesToB64u, registrationOptions, assertionOptions, enroll, approve };
})();
