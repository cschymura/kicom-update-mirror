# Mirage Engram DEV-33 — install preparation (2026-09-20)

Scope: offline install preparation only. Branch predecessor `01ea4be0ca5f76260ed02a4519ce793a67b9e160`. No production installation, private host config, credentials, private memories or activation.

Original CI package: `KiCom-0.9.27-Engram-INTEGRATED-DISABLED.zip` from Actions run 35534000724, artifact 10611249893, SHA256 `38e9fce1a2d3f31fda45b04bfd29c13b38a05d0fb7033b1f9916a68461185d26`. Release bytes preserved.

New local preflight verifies archive hash, exact 51 file members and all native manifest hashes, PHP lint of 34 scripts, native memory API disabled without private config; 7/7 new regression tests pass. DEV-32 isolated connector reference 8/8 synthetic tests pass; not a real ChatGPT app.

A separate local handoff package `Mirage-Engram-DEV33-Installationsvorbereitung.zip` was built and independently unpacked/retested (7+8 passes), SHA256 `1eeb98beeb2a9481ebd32ba45cc865cf0cf15d4be7df68b347bc3fac90274e6a`. It exists only as a conversation artifact; no GitHub code commit or server deployment implied.

Next gated steps: operator approval for original native red-risk self-update in authenticated KiCom admin, actual host path/alias/PHP UID verification and private runtime provisioning, then genuine supported and connected ChatGPT authentication integration and an independent new-conversation synthetic recall demonstration. Merely deploying 0.9.27 leaves private Engram disabled.
