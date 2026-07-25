// The package entry point. Both halves are re-exported from here so a front end
// imports its site's section types and the client from one place.
//
// `types.js` is generated from your site's schemas — regenerate it whenever a
// section or collection changes. See the README.

export * from './types.js';
export * from './client.js';
