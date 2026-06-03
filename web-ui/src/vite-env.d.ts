/// <reference types="vite/client" />

// @phlix/ui exposes its stylesheet + fonts as plain CSS via package `exports`
// subpaths. Declare these side-effect imports so vue-tsc doesn't error on the
// untyped .css modules (the imports are bundled by Vite at build time).
declare module '@phlix/ui/style.css';
declare module '@phlix/ui/fonts.css';
