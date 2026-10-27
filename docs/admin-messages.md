# Editor messages in English and German

The **Hinweise / Messages** selector chooses the language for errors and media warnings. It is available before sign-in as well as inside the CMS. The initial preference follows the browser language; a manual choice is saved in this browser. Changing it updates messages already on screen. It does not change a page's content language or translate authored content, field labels, or the rest of the administration interface.

German messages cover common authentication errors, schema validation constraints, connection failures, expired sessions, save conflicts, and media quality/accessibility warnings. Image recommendations use the server's quality verdict and dimensions.

Unexpected server or plugin errors show German recovery guidance with an expandable **Technische Details** control. The original diagnostic remains available there for support; it is rendered as text, never HTML. English preserves the original diagnostic. API responses and stored documents are unchanged.

For new error displays, use `LocalizedError` instead of interpolating raw errors. Add known messages or parameterized validation patterns to `admin-ui/src/lib/messages.js`. Use `message()` for static warning text and `imageQualityMessage()` for server image verdicts. Keep user-authored text and identifiers outside the translation catalog.
