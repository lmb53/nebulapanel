/* Passkey (WebAuthn) helpers shared by the account page and the sign-in step.
 * Converts the server's base64url JSON options to the binary form the
 * browser API wants, and the browser's response back to JSON. */
(function () {
  'use strict';
  const toBytes = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), (c) => c.charCodeAt(0));
  const toB64url = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const supported = () => !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
  async function create(options) {
    const publicKey = { ...options, challenge: toBytes(options.challenge), user: { ...options.user, id: toBytes(options.user.id) },
      excludeCredentials: (options.excludeCredentials || []).map((c) => ({ ...c, id: toBytes(c.id) })) };
    const cred = await navigator.credentials.create({ publicKey });
    return { id: cred.id, clientDataJSON: toB64url(cred.response.clientDataJSON), attestationObject: toB64url(cred.response.attestationObject) };
  }
  async function get(options) {
    const publicKey = { ...options, challenge: toBytes(options.challenge), allowCredentials: (options.allowCredentials || []).map((c) => ({ ...c, id: toBytes(c.id) })) };
    const cred = await navigator.credentials.get({ publicKey });
    return { id: cred.id, clientDataJSON: toB64url(cred.response.clientDataJSON), authenticatorData: toB64url(cred.response.authenticatorData), signature: toB64url(cred.response.signature) };
  }
  const message = (err) => (err && err.name === 'NotAllowedError') ? 'The passkey request was cancelled or timed out.'
    : (err && err.name === 'InvalidStateError') ? 'This passkey is already registered.' : ((err && err.message) || 'The passkey request failed.');
  window.NebulaPasskeys = { supported, create, get, message };
})();
