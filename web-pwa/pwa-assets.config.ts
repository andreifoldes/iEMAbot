import { defineConfig, minimal2023Preset } from '@vite-pwa/assets-generator/config';

// Generates the PWA icon set into public/ from the iEMAbot logo.
// Run with: npm run generate-pwa-assets
export default defineConfig({
  preset: {
    ...minimal2023Preset,
    // Pad the maskable icon onto the brand green so the square logo isn't
    // clipped inside the platform's safe-zone mask.
    maskable: {
      ...minimal2023Preset.maskable,
      resizeOptions: { background: '#075E54', fit: 'contain' },
      padding: 0.3,
    },
  },
  images: ['public/esmira-logo.svg'],
});
