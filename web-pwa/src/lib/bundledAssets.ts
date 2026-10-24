/**
 * Native app: study text may point at files the PWA itself serves from /pwa/ (e.g. the
 * cookie-theft picture). The APK already contains those files, so point at the bundled copy and the
 * picture shows without a connection. Only names present in the bundle are rewritten; anything else
 * stays a network URL.
 */
export function rewriteBundledAssets<T>(study: T, bundled: readonly string[]): T {
  if (!bundled.length) return study;
  const names = bundled.map((n) => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|');
  const re = new RegExp(`https?://[^/"\\s\\\\]+/pwa/(${names})(?=["'?#\\s\\\\)])`, 'g');
  return JSON.parse(JSON.stringify(study).replace(re, '$1')) as T;
}
