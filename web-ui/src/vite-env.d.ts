/// <reference types="vite/client" />

interface ImportMetaEnv {
    /** Build-time locale override for the ui messages seam (src/i18n). Wins over
     *  navigator.language, loses to an explicit runtime value. Unset/'en' keeps
     *  the byte-identical English ui defaults (the 'en' catalog is an EMPTY
     *  override). */
    readonly VITE_PHLIX_LOCALE?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}

// @phlix/ui exposes its stylesheet + fonts as plain CSS via package `exports`
// subpaths. Declare these side-effect imports so vue-tsc doesn't error on the
// untyped .css modules (the imports are bundled by Vite at build time).
declare module '@phlix/ui/style.css';
declare module '@phlix/ui/fonts.css';
