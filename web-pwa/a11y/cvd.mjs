/**
 * Colour-vision-deficiency (CVD) maths for the accessibility audit. Pure functions, no
 * browser, so they are unit-tested in cvd.test.mjs.
 *
 * Simulation: Machado, Oliveira & Fernandes (2009), "A Physiologically-based Model for
 * Simulation of Color Vision Deficiency", severity 1.0 (complete dichromacy), applied in
 * linear sRGB. Achromatopsia is plain relative luminance. Distance: CIEDE2000 in CIELAB
 * (D65).
 *
 * Colours are `[r, g, b]` with 0–255 channels (sRGB).
 */

export const CVD_TYPES = ['protanopia', 'deuteranopia', 'tritanopia', 'achromatopsia'];

// Rows act on linear [r, g, b].
const MATRICES = {
  protanopia: [
    [0.152286, 1.052583, -0.204868],
    [0.114503, 0.786281, 0.099216],
    [-0.003882, -0.048116, 1.051998],
  ],
  deuteranopia: [
    [0.367322, 0.860646, -0.227968],
    [0.280085, 0.672501, 0.047413],
    [-0.01182, 0.04294, 0.968881],
  ],
  tritanopia: [
    [1.255528, -0.076749, -0.178779],
    [-0.078411, 0.930809, 0.147602],
    [0.004733, 0.691367, 0.3039],
  ],
  achromatopsia: [
    [0.2126, 0.7152, 0.0722],
    [0.2126, 0.7152, 0.0722],
    [0.2126, 0.7152, 0.0722],
  ],
};

const clamp01 = (x) => Math.min(1, Math.max(0, x));
const toLinear = (c) => {
  const s = c / 255;
  return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
};
const fromLinear = (l) => {
  const v = clamp01(l);
  return 255 * (v <= 0.0031308 ? v * 12.92 : 1.055 * v ** (1 / 2.4) - 0.055);
};

/** How `rgb` looks to someone with the given deficiency. Returns 0–255 floats. */
export function simulate(rgb, type) {
  const m = MATRICES[type];
  if (!m) throw new Error(`Unknown CVD type: ${type}`);
  const lin = rgb.map(toLinear);
  return m.map((row) => fromLinear(row[0] * lin[0] + row[1] * lin[1] + row[2] * lin[2]));
}

/** WCAG relative luminance (0–1). */
export function luminance(rgb) {
  const [r, g, b] = rgb.map(toLinear);
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG contrast ratio (1–21). */
export function contrastRatio(a, b) {
  const la = luminance(a);
  const lb = luminance(b);
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/** sRGB (0–255) → CIELAB, D65. */
export function toLab(rgb) {
  const [r, g, b] = rgb.map(toLinear);
  const x = (0.4124564 * r + 0.3575761 * g + 0.1804375 * b) / 0.95047;
  const y = 0.2126729 * r + 0.7151522 * g + 0.072175 * b;
  const z = (0.0193339 * r + 0.119192 * g + 0.9503041 * b) / 1.08883;
  const f = (t) => (t > 216 / 24389 ? Math.cbrt(t) : (24389 / 27 * t + 16) / 116);
  const [fx, fy, fz] = [f(x), f(y), f(z)];
  return [116 * fy - 16, 500 * (fx - fy), 200 * (fy - fz)];
}

/** Lab chroma C*ab: how colourful a colour is, independent of lightness. */
export function chroma(rgb) {
  const [, a, b] = toLab(rgb);
  return Math.hypot(a, b);
}

const rad = (d) => (d * Math.PI) / 180;
const deg = (r) => (r * 180) / Math.PI;

/** CIEDE2000 colour difference between two Lab colours (Sharma, Wu & Dalal 2005). */
export function deltaE2000(lab1, lab2) {
  const [L1, a1, b1] = lab1;
  const [L2, a2, b2] = lab2;
  const C1 = Math.hypot(a1, b1);
  const C2 = Math.hypot(a2, b2);
  const Cbar = (C1 + C2) / 2;
  const G = 0.5 * (1 - Math.sqrt(Cbar ** 7 / (Cbar ** 7 + 25 ** 7)));
  const a1p = (1 + G) * a1;
  const a2p = (1 + G) * a2;
  const C1p = Math.hypot(a1p, b1);
  const C2p = Math.hypot(a2p, b2);
  const hue = (b, ap) => (b === 0 && ap === 0 ? 0 : (deg(Math.atan2(b, ap)) + 360) % 360);
  const h1p = hue(b1, a1p);
  const h2p = hue(b2, a2p);

  const dLp = L2 - L1;
  const dCp = C2p - C1p;
  let dhp = 0;
  if (C1p * C2p !== 0) {
    dhp = h2p - h1p;
    if (dhp > 180) dhp -= 360;
    else if (dhp < -180) dhp += 360;
  }
  const dHp = 2 * Math.sqrt(C1p * C2p) * Math.sin(rad(dhp / 2));

  const Lbarp = (L1 + L2) / 2;
  const Cbarp = (C1p + C2p) / 2;
  let hbarp = h1p + h2p;
  if (C1p * C2p === 0) hbarp = h1p + h2p;
  else if (Math.abs(h1p - h2p) <= 180) hbarp /= 2;
  else hbarp = (hbarp + (hbarp < 360 ? 360 : -360)) / 2;

  const T =
    1 -
    0.17 * Math.cos(rad(hbarp - 30)) +
    0.24 * Math.cos(rad(2 * hbarp)) +
    0.32 * Math.cos(rad(3 * hbarp + 6)) -
    0.2 * Math.cos(rad(4 * hbarp - 63));
  const dTheta = 30 * Math.exp(-(((hbarp - 275) / 25) ** 2));
  const Rc = 2 * Math.sqrt(Cbarp ** 7 / (Cbarp ** 7 + 25 ** 7));
  const Sl = 1 + (0.015 * (Lbarp - 50) ** 2) / Math.sqrt(20 + (Lbarp - 50) ** 2);
  const Sc = 1 + 0.045 * Cbarp;
  const Sh = 1 + 0.015 * Cbarp * T;
  const Rt = -Math.sin(rad(2 * dTheta)) * Rc;

  return Math.sqrt(
    (dLp / Sl) ** 2 + (dCp / Sc) ** 2 + (dHp / Sh) ** 2 + Rt * (dCp / Sc) * (dHp / Sh),
  );
}

/** Perceived difference between two sRGB colours as seen with `type` (or normally if null). */
export function perceivedDifference(a, b, type = null) {
  const sa = type ? simulate(a, type) : a;
  const sb = type ? simulate(b, type) : b;
  return deltaE2000(toLab(sa), toLab(sb));
}

/**
 * Two colours are *confusable* under a deficiency when they are clearly different to a
 * typical viewer, yet that deficiency leaves them close in both colour and lightness. A
 * lightness difference (WCAG non-text contrast, 3:1) is enough to tell shapes apart even
 * when the hues collapse, so such pairs are not flagged.
 *
 * @returns {{confusable: boolean, normal: number, simulated: number, contrast: number}}
 */
export function confusability(a, b, type, opts = {}) {
  const { distinctAbove = 20, confusableBelow = 12, minContrast = 3 } = opts;
  const normal = perceivedDifference(a, b);
  const simulated = perceivedDifference(a, b, type);
  const contrast = contrastRatio(simulate(a, type), simulate(b, type));
  return {
    confusable: normal >= distinctAbove && simulated < confusableBelow && contrast < minContrast,
    normal,
    simulated,
    contrast,
  };
}
