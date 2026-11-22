{{-- Browser side of passkeys, native WebAuthn only (no library). Hidden when the browser can't do it. --}}
@once
    <script>
        window.passkeys826 = {
            supported() {
                return window.isSecureContext
                    && !!window.PublicKeyCredential
                    && typeof PublicKeyCredential.parseCreationOptionsFromJSON === 'function'
                    && typeof PublicKeyCredential.parseRequestOptionsFromJSON === 'function';
            },

            async conditionalSupported() {
                try {
                    return this.supported() && (await PublicKeyCredential.getClientCapabilities()).conditionalGet === true;
                } catch (e) {
                    return false;
                }
            },

            // Plain words for what the browser throws
            explain(e) {
                switch (e && e.name) {
                    case 'NotAllowedError': return 'Cancelled or timed out. Try again when you are ready.';
                    case 'AbortError': return null;
                    case 'InvalidStateError': return 'This device already has a passkey for your account.';
                    case 'SecurityError': return 'Passkeys do not work on this address. They need https (or localhost).';
                    case 'NotSupportedError': return 'This device cannot make a passkey that asks for your PIN or fingerprint.';
                    default: return 'Something went wrong with the passkey. Try again.';
                }
            },

            // Keeps the password manager's list in step with the server (Chrome, Safari; ignored elsewhere)
            async signal({ rpId, userId, ids }) {
                if (!window.PublicKeyCredential || !PublicKeyCredential.signalAllAcceptedCredentials || !rpId || !userId) return;
                try {
                    await PublicKeyCredential.signalAllAcceptedCredentials({ rpId, userId, allAcceptedCredentialIds: ids });
                } catch (e) {
                    console.warn('Passkey list not synced:', e);
                }
            },

            login(config) {
                return {
                    ok: false,
                    busy: false,
                    message: '',
                    abort: null,

                    async init() {
                        this.ok = window.passkeys826.supported();
                        if (this.ok && await window.passkeys826.conditionalSupported()) {
                            this.autofill();
                        }
                    },

                    async options() {
                        const response = await fetch(config.optionsUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                        if (response.status === 429) throw new Error('Too many tries. Wait a minute and try again.');
                        if (!response.ok) throw new Error('Passkey sign-in is not available right now.');
                        return PublicKeyCredential.parseRequestOptionsFromJSON(await response.json());
                    },

                    // Passkeys offered in the email field's autofill list
                    async autofill() {
                        this.abort = new AbortController();
                        try {
                            const credential = await navigator.credentials.get({
                                publicKey: await this.options(),
                                mediation: 'conditional',
                                signal: this.abort.signal,
                            });
                            await this.send(credential);
                        } catch (e) {
                            if (e instanceof DOMException) return;
                            this.message = e.message;
                        }
                    },

                    async signIn() {
                        this.message = '';
                        this.busy = true;
                        if (this.abort) this.abort.abort();
                        try {
                            await this.send(await navigator.credentials.get({ publicKey: await this.options() }));
                        } catch (e) {
                            this.message = e instanceof DOMException ? window.passkeys826.explain(e) : e.message;
                            this.busy = false;
                            this.autofill();
                        }
                    },

                    async send(credential) {
                        const response = await fetch(config.loginUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config.token },
                            body: JSON.stringify({ credential: JSON.stringify(credential.toJSON()) }),
                        });
                        const body = await response.json().catch(() => ({}));
                        if (response.ok && body.redirect) {
                            window.location.href = body.redirect;
                            return;
                        }
                        this.busy = false;
                        throw new Error(response.status === 429 ? 'Too many tries. Wait a minute and try again.'
                            : response.status === 419 ? 'This page expired. Reload it and try again.'
                            : (body.message || 'That passkey could not sign you in.'));
                    },
                };
            },

            manage(config) {
                return {
                    ok: false,
                    busy: false,
                    name: '',
                    message: '',

                    async init() {
                        this.ok = window.passkeys826.supported();
                        await this.sync();
                    },

                    async sync() {
                        const state = await this.$wire.passkeyState();
                        await window.passkeys826.signal(state);
                    },

                    async add() {
                        this.message = '';
                        this.busy = true;
                        try {
                            const options = JSON.parse(await this.$wire.passkeyOptions());
                            const credential = await navigator.credentials.create({
                                publicKey: PublicKeyCredential.parseCreationOptionsFromJSON(options),
                            });
                            const result = await this.$wire.storePasskey(JSON.stringify(credential.toJSON()), this.name);
                            if (result.ok) {
                                this.name = '';
                                await this.$wire.$refresh();
                                await this.sync();
                            } else {
                                this.message = result.message;
                            }
                        } catch (e) {
                            this.message = e instanceof DOMException ? window.passkeys826.explain(e) : 'Something went wrong with the passkey. Try again.';
                        }
                        this.busy = false;
                    },
                };
            },
        };
    </script>
@endonce
