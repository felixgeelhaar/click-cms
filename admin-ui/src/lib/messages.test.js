import { beforeEach, describe, expect, it } from 'vitest';
import { errorTranslation, imageQualityMessage, message, messageLanguage, setMessageLanguage, supportedLanguage } from './messages.js';
beforeEach(() => { messageLanguage.value = 'en'; });
describe('editor messages', () => {
  it('normalizes German preferences and safely defaults unsupported languages', () => {
    expect(supportedLanguage('de-CH')).toBe('de');
    expect(supportedLanguage('fr')).toBe('en');
    expect(supportedLanguage(null)).toBe('en');
  });
  it('preserves English errors exactly', () => {
    expect(errorTranslation('Invalid credentials')).toEqual({text:'Invalid credentials', technical:''});
  });
  it('translates validation while preserving field labels, constraints and options', () => {
    setMessageLanguage('de');
    expect(errorTranslation('Überschrift is required.').text).toBe('Bitte „Überschrift“ ausfüllen.');
    expect(errorTranslation('Titel must be at most 120 characters.').text).toContain('120 Zeichen');
    expect(errorTranslation('Link must be a valid URL or an on-site path like /contact.').text).toContain('/contact');
    expect(errorTranslation('Typ must be one of: primary, secondary.').text).toContain('primary, secondary');
  });
  it('translates authentication and gives safe recovery guidance for conflicts', () => {
    setMessageLanguage('de');
    expect(errorTranslation('Invalid credentials').text).toContain('Passwort');
    expect(errorTranslation('Revision conflict').text).toContain('Änderungen sichern');
    expect(errorTranslation('The new password must be at least 12 characters.').text).toContain('12 Zeichen');
  });
  it('keeps unknown plugin diagnostics available separately from German guidance', () => {
    setMessageLanguage('de');
    const result = errorTranslation('Acme plugin: E_UPLOAD_42');
    expect(result.text).toContain('technischen Details');
    expect(result.technical).toBe('Acme plugin: E_UPLOAD_42');
    expect(result.text).not.toContain('Acme');
  });
  it('uses server quality verdicts and dimensions without changing them', () => {
    const quality={warning:true, sourceWidth:1280, displayWidth:1280, recommendedWidth:2560, message:'English quality warning'};
    expect(imageQualityMessage(quality)).toBe(quality.message);
    setMessageLanguage('de');
    expect(imageQualityMessage(quality)).toContain('2560');
    expect(imageQualityMessage({...quality, warning:false})).toBe('');
    expect(quality.message).toBe('English quality warning');
    expect(message('Change image')).toBe('Bild ändern');
  });
});

it('interpolates UI parameters without translating or interpreting authored values', () => {
  const title = '<b>Save</b> {1}';
  expect(message('Delete {0}', [title])).toBe('Delete ' + title);
  messageLanguage.value = 'de';
  expect(message('Delete {0}', [title])).toBe(title + ' löschen');
  expect(message('Saved “My English title”.')).toBe('„My English title“ gespeichert.');
  expect(message('unknown plugin label')).toBe('unknown plugin label');
});

it('translates plugin refusals while preserving dependency names', () => {
  messageLanguage.value = 'de';
  expect(errorTranslation('Plugin blocked by deployment policy').text).toContain('Bereitstellungsrichtlinie');
  expect(errorTranslation('Missing dependencies: Search API').text).toBe('Erforderliche Plugins fehlen: Search API');
  expect(errorTranslation('Cannot deactivate - other plugins depend on it: Forms').text).toContain('benötigt: Forms');
});
