import { ref } from 'vue';
import interfaceGerman from './locales/de.json';

export function supportedLanguage(value) {
  return /^de(?:-|$)/i.test(String(value ?? '')) ? 'de' : 'en';
}
function initialLanguage() {
  try {
    const saved = globalThis.window?.localStorage?.getItem('click-message-language');
    if (saved === 'de' || saved === 'en') return saved;
  } catch { /* Storage can be disabled. Browser preference still works. */ }
  return supportedLanguage(globalThis.navigator?.language);
}
export const messageLanguage = ref(initialLanguage());
export function setMessageLanguage(value) {
  messageLanguage.value = supportedLanguage(value);
  try { globalThis.window?.localStorage?.setItem('click-message-language', messageLanguage.value); } catch { /* Session preference remains usable. */ }
}

const german = {
 'Could not load this version:': 'Diese Version konnte nicht geladen werden:',
 'Refresh the history and try again.': 'Bitte den Verlauf aktualisieren und erneut versuchen.',
"The page changed after this review was requested. Refresh the request for the current saved version.": "Die Seite wurde seit der Prüfanfrage geändert. Bitte die Prüfung für die aktuelle gespeicherte Version erneut anfordern.",
"The page changed after approval. Save the current version and request another review.": "Die Seite wurde nach der Freigabe geändert. Bitte speichern und eine neue Prüfung anfordern.",
"The page changed after the review was completed. Save the current version and request another review.": "Die Seite wurde nach Abschluss der Prüfung geändert. Bitte speichern und eine neue Prüfung anfordern.",
"This entry changed remotely. Your edits are still here. Reload to review the latest version.": "Dieser Eintrag wurde inzwischen geändert. Deine Änderungen sind noch vorhanden. Bitte die aktuelle Version zur Prüfung neu laden.",
"This installation cannot make outbound HTTP requests. Webhooks will not be delivered until the administration enables curl or allow_url_fopen.": "Diese Installation kann keine ausgehenden HTTP-Anfragen senden. Webhooks werden erst zugestellt, wenn die Administration curl oder allow_url_fopen aktiviert.",
"This reference is not in the media library. Choose a file from the library to replace it.": "Diese Referenz ist nicht in der Mediathek. Wähle eine Datei aus der Mediathek, um sie zu ersetzen.",

  "This page changed remotely. Your edits are still here. Compare and combine them with the latest saved version, or reload to review it.": "Diese Seite wurde inzwischen geändert. Deine Änderungen sind noch vorhanden. Vergleiche sie mit der gespeicherten Version und führe sie zusammen, oder lade die Seite zur Prüfung neu.",
  "This entry changed remotely. Your edits are still here. Reload to review the latest saved version.": "Dieser Eintrag wurde inzwischen geändert. Deine Änderungen sind noch vorhanden. Bitte die aktuelle gespeicherte Version zur Prüfung neu laden.",
  "The page changed after this review. Request review again for the current saved version.": "Die Seite wurde seit dieser Prüfung geändert. Bitte die aktuelle gespeicherte Version erneut prüfen lassen.",
  "Saved translations changed while the comparison loaded. Reload to compare the current saved versions.": "Die gespeicherten Übersetzungen wurden während des Ladens geändert. Bitte neu laden, um die aktuellen Versionen zu vergleichen.",
  "Source changed since this review. Review the saved comparison again.": "Der Ausgangstext wurde seit dieser Prüfung geändert. Bitte den gespeicherten Vergleich erneut prüfen.",
  "Target changed since this review. Review the saved comparison again.": "Die Übersetzung wurde seit dieser Prüfung geändert. Bitte den gespeicherten Vergleich erneut prüfen.",
  "This snapshot no longer matches the site's section schema and cannot be adopted.": "Diese Vorlage passt nicht mehr zum aktuellen Aufbau der Abschnitte und kann nicht übernommen werden.",
  "Some section types could not be loaded:": "Einige Abschnittstypen konnten nicht geladen werden:",
  "Could not copy to the clipboard.": "Der Inhalt konnte nicht in die Zwischenablage kopiert werden. Bitte manuell kopieren.",
  "Password must be at most 72 bytes (some characters use more than one byte).": "Das Passwort darf höchstens 72 Bytes lang sein. Manche Zeichen benötigen mehrere Bytes. Bitte ein kürzeres Passwort wählen.",
  "Compare with saved version": "Mit gespeicherter Version vergleichen",
  "Reload to review": "Zur Prüfung neu laden",
  "Image resizing is unavailable on this server, so uploads are stored at their original size only. Install the PHP gd extension to generate responsive versions.": "Dieser Server kann Bilder derzeit nicht verkleinern. Hochgeladene Bilder werden nur in Originalgröße gespeichert. Die Administration kann die PHP-Erweiterung gd aktivieren, um passende Bildgrößen zu erzeugen.",

  'Invalid credentials': 'Benutzername oder Passwort ist nicht korrekt.',
  'Username and password required': 'Bitte Benutzername und Passwort eingeben.',
  'Not authenticated': 'Bitte erneut anmelden.',
  'Unauthorized': 'Bitte erneut anmelden.',
  'Forbidden': 'Für diese Aktion fehlt dir die Berechtigung.',
  'Permission denied': 'Für diese Aktion fehlt dir die Berechtigung.',
  'Not found': 'Der angeforderte Inhalt wurde nicht gefunden.',
  'Page not found': 'Diese Seite wurde nicht gefunden.',
  'Failed to fetch': 'Die Verbindung zum Server ist unterbrochen. Bitte die Internetverbindung prüfen und erneut versuchen.',
  'NetworkError when attempting to fetch resource.': 'Die Verbindung zum Server ist unterbrochen. Bitte die Internetverbindung prüfen und erneut versuchen.',
  'Load failed': 'Die Verbindung zum Server ist unterbrochen. Bitte erneut versuchen.',
  'The current password is not correct.': 'Das aktuelle Passwort ist nicht korrekt.',
  'Both the current and the new password are required.': 'Bitte das aktuelle und das neue Passwort eingeben.',
  'The new password must differ from the current one.': 'Das neue Passwort muss sich vom aktuellen unterscheiden.',
  'That password cannot be used.': 'Dieses Passwort kann nicht verwendet werden. Bitte ein anderes wählen.',
  'The two entries do not match.': 'Die beiden Eingaben stimmen nicht überein.',
  'This field is required.': 'Bitte dieses Feld ausfüllen.',
  'This field is required': 'Bitte dieses Feld ausfüllen.',
  'Required': 'Bitte dieses Feld ausfüllen.',
  'Invalid email address': 'Bitte eine gültige E-Mail-Adresse eingeben.',
  'Please enter a valid email address.': 'Bitte eine gültige E-Mail-Adresse eingeben.',
  'Please enter a valid URL.': 'Bitte eine gültige Webadresse eingeben.',
  'Please enter a valid number.': 'Bitte eine gültige Zahl eingeben.',
  'Could not load collections.': 'Die Sammlungen konnten nicht geladen werden. Bitte erneut versuchen.',
  'That collection type was not found.': 'Diese Sammlung wurde nicht gefunden.',
  'The original saved draft is no longer available. Reload the page to review it.': 'Der ursprüngliche Entwurf ist nicht mehr verfügbar. Bitte die Seite neu laden und prüfen.',
  'The latest revision token is missing. Reload before merging.': 'Die aktuelle Versionskennung fehlt. Bitte die Seite vor dem Zusammenführen neu laden.',
  'Could not read the latest saved version.': 'Die zuletzt gespeicherte Version konnte nicht geladen werden. Bitte erneut versuchen.',
  'The page changed after it was loaded. Reload the page, save it, then request review again.': 'Die Seite wurde inzwischen geändert. Bitte neu laden, speichern und die Prüfung erneut anfordern.',
  'The library could not be loaded. Reload the page to try again.': 'Die Mediathek konnte nicht geladen werden. Bitte die Seite neu laden und erneut versuchen.',
  'No description set — add one in the Media library so screen readers can describe it.': 'Eine Bildbeschreibung fehlt. Bitte in der Mediathek ergänzen, damit Screenreader das Bild beschreiben können.',
  'This is a large file to send to a visitor. Nothing downloads until they press play, but under about 20 MB is kinder on a phone.': 'Diese Videodatei ist groß. Sie wird erst beim Abspielen geladen. Unter etwa 20 MB ist sie auf dem Handy leichter abzuspielen.',
  'Remove': 'Entfernen', 'Clear': 'Entfernen', 'Close library': 'Mediathek schließen',
  'Change image': 'Bild ändern', 'Choose image': 'Bild auswählen',
  'Change video': 'Video ändern', 'Choose video': 'Video auswählen',
  'Loading library…': 'Mediathek wird geladen…',
  'The library is empty — upload images on the Media page first.': 'Die Mediathek ist leer. Bitte zuerst unter Medien Bilder hochladen.',
  'No video in the library — upload one on the Media page first.': 'Die Mediathek enthält kein Video. Bitte zuerst unter Medien ein Video hochladen.',
  'Scalable vector (SVG)': 'Skalierbare Vektorgrafik (SVG)',
};

// Parameterized messages keep filenames, titles and counts outside translation keys.
const interfacePatterns = Object.entries(interfaceGerman)
  .filter(([key]) => /\{\d+\}/.test(key))
  .map(([key, translation]) => {
    const slots = [];
    const escaped = key.split(/(\{\d+\})/).map(part => {
      if (/^\{\d+\}$/.test(part)) { slots.push(part); return '(.*?)'; }
      return part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }).join('');
    return { expression: new RegExp('^' + escaped + '$', 's'), translation, slots };
  });
export function message(text, parameters) {
  if (typeof text !== 'string') return text;
  let result = text;
  if (messageLanguage.value === 'de') {
    result = interfaceGerman[text] ?? german[text];
    if (result === undefined && !parameters) {
      for (const { expression, translation, slots } of interfacePatterns) {
        const match = text.match(expression);
        if (match) {
          const values = Object.fromEntries(slots.map((slot, index) => [slot, match[index + 1]]));
          result = translation.replace(/\{\d+\}/g, slot => values[slot] ?? slot);
          break;
        }
      }
    }
    result ??= text;
  }
  return parameters ? result.replace(/\{(\d+)\}/g, (slot, index) => String(parameters[index] ?? slot)) : result;
}

const patterns = [
  [/^(.+) is required\.$/, label => `Bitte „${label}“ ausfüllen.`],
  [/^(.+) must be at least (\d+) characters\.$/, (label, n) => `„${label}“ muss mindestens ${n} Zeichen enthalten.`],
  [/^(.+) must be at most (\d+) characters\.$/, (label, n) => `„${label}“ darf höchstens ${n} Zeichen enthalten.`],
  [/^(.+) must be at least (-?[\d.]+)\.$/, (label, n) => `„${label}“ muss mindestens ${n} betragen.`],
  [/^(.+) must be at most (-?[\d.]+)\.$/, (label, n) => `„${label}“ darf höchstens ${n} betragen.`],
  [/^(.+) must be text\.$/, label => `Bitte für „${label}“ einen Text eingeben.`],
  [/^(.+) must be a number\.$/, label => `Bitte für „${label}“ eine Zahl eingeben.`],
  [/^(.+) must be a valid email address\.$/, label => `Bitte für „${label}“ eine gültige E-Mail-Adresse eingeben.`],
  [/^(.+) must be a valid URL or an on-site path like \/contact\.$/, label => `Bitte für „${label}“ eine gültige Webadresse oder einen Seitenpfad wie /contact eingeben.`],
  [/^(.+) must be a date(?: in YYYY-MM-DD format)?\.$/, label => `Bitte für „${label}“ ein Datum im Format JJJJ-MM-TT eingeben.`],
  [/^(.+) must be one of: (.+)\.$/, (label, options) => `Bitte für „${label}“ einen dieser Werte wählen: ${options}.`],
  [/^(.+) (?:may have|must have) at (least|most) (\d+) (entries|lines)\.$/, (label, limit, n, kind) => `„${label}“ ${limit === 'least' ? 'benötigt mindestens' : 'erlaubt höchstens'} ${n} ${kind === 'entries' ? 'Einträge' : 'Zeilen'}.`],

  [/^The new password must be at least (\d+) characters\.$/, n => `Das neue Passwort muss mindestens ${n} Zeichen lang sein.`],
  [/^At least (\d+) characters\.$/, n => `Mindestens ${n} Zeichen.`],
  [/^Must be at least (\d+) characters\.?$/, n => `Bitte mindestens ${n} Zeichen eingeben.`],
  [/^Must be at most (\d+) characters\.?$/, n => `Bitte höchstens ${n} Zeichen eingeben.`],
  [/^Request failed \((\d+)\)\.?$/, n => `Die Anfrage ist fehlgeschlagen (HTTP ${n}). Bitte erneut versuchen.`],
  [/^(?:HTTP )(\d+)$/, n => `Die Anfrage ist fehlgeschlagen (HTTP ${n}).`],
  [/^Too many (?:login )?attempts.*$/i, () => 'Zu viele Versuche. Bitte etwas warten und erneut versuchen.'],
  [/^.*(?:CSRF|session expired|Session expired).*$/, () => 'Die Sitzung ist abgelaufen. Bitte erneut anmelden. Noch offene Änderungen vorher sichern.'],
  [/^.*(?:changed since|changed by another|revision conflict|Revision conflict|Conflict).*$/, () => 'Der Inhalt wurde inzwischen geändert. Bitte deine Änderungen sichern, die aktuelle Version laden und die Änderungen vergleichen.'],
];

/** Only error display slots use this. Never translate authored content or API data. */
export function errorTranslation(value) {
  const text = String(value ?? '');
  if (!text || messageLanguage.value === 'en') return { text, technical: '' };
  if (german[text]) return { text: german[text], technical: '' };
  for (const [pattern, translate] of patterns) {
    const match = text.match(pattern);
    if (match) return { text: translate(...match.slice(1)), technical: '' };
  }
  // Keep unknown plugin/server diagnostics available without presenting English
  // as the editor's only explanation, or guessing what failed.
  return { text: 'Die Aktion konnte nicht abgeschlossen werden. Bitte erneut versuchen. Falls das Problem bleibt, die technischen Details an die Administration weitergeben.', technical: text };
}

/** Uses the server's verdict and dimensions, not a duplicate of its quality rules. */
export function imageQualityMessage(quality) {
  if (messageLanguage.value !== 'de') return quality?.message ?? '';
  if (!quality?.warning) return '';
  const { sourceWidth, displayWidth, recommendedWidth } = quality;
  if (displayWidth) return `Das Bild ist ${sourceWidth} Pixel breit und wird hier mit ${displayWidth} Pixeln angezeigt. Für eine scharfe Darstellung auf hochauflösenden Bildschirmen bitte ein Bild mit etwa ${recommendedWidth} Pixeln Breite hochladen.`;
  return `Das Bild ist ${sourceWidth} Pixel breit. Bei großer Darstellung kann es unscharf wirken. Bitte ein Bild mit etwa ${recommendedWidth} Pixeln Breite hochladen.`;
}
