import {themes as prismThemes} from 'prism-react-renderer';
import type {Config} from '@docusaurus/types';
import type * as Preset from '@docusaurus/preset-classic';

// This runs in Node.js - Don't use client-side code here (browser APIs, JSX...)

const config: Config = {
  title: 'iEMAbot / ESMira Fork',
  tagline:
    'Open-source, self-hosted EMA for circadian and sleep research: ESMira backend + installable participant PWA',
  favicon: 'img/favicon.ico',

  future: {
    v4: true,
  },

  // GitHub Pages project site for github.com/andreifoldes/iEMAbot
  url: 'https://andreifoldes.github.io',
  baseUrl: '/iEMAbot/',
  trailingSlash: false,

  organizationName: 'andreifoldes',
  projectName: 'iEMAbot',

  onBrokenLinks: 'throw',
  onBrokenAnchors: 'throw',

  markdown: {
    mermaid: true,
    hooks: {
      onBrokenMarkdownLinks: 'throw',
    },
  },
  themes: ['@docusaurus/theme-mermaid'],

  i18n: {
    defaultLocale: 'en',
    locales: ['en'],
  },

  presets: [
    [
      'classic',
      {
        docs: {
          // Docs are the site: served from the root, landing page is docs/index.md
          routeBasePath: '/',
          sidebarPath: './sidebars.ts',
          editUrl: 'https://github.com/andreifoldes/iEMAbot/edit/main/website/',
        },
        blog: false,
        theme: {
          customCss: './src/css/custom.css',
        },
      } satisfies Preset.Options,
    ],
  ],

  themeConfig: {
    colorMode: {
      respectPrefersColorScheme: true,
    },
    navbar: {
      title: 'iEMAbot / ESMira Fork',
      logo: {
        alt: 'iEMAbot',
        src: 'img/logo.png',
        width: 32,
        height: 32,
      },
      items: [
        {type: 'docSidebar', sidebarId: 'docs', position: 'left', label: 'Documentation'},
        {to: '/current-status', label: 'Current status', position: 'left'},
        {
          href: 'https://github.com/andreifoldes/iEMAbot',
          label: 'GitHub (fork)',
          position: 'right',
        },
        {
          href: 'https://github.com/KL-Psychological-Methodology/ESMira-web',
          label: 'Upstream ESMira',
          position: 'right',
        },
      ],
    },
    footer: {
      style: 'dark',
      links: [
        {
          title: 'This project',
          items: [
            {label: 'Fork repository', href: 'https://github.com/andreifoldes/iEMAbot'},
            {label: 'Current status', to: '/current-status'},
          ],
        },
        {
          title: 'Upstream ESMira',
          items: [
            {label: 'ESMira-web', href: 'https://github.com/KL-Psychological-Methodology/ESMira-web'},
            {label: 'ESMira wiki', href: 'https://github.com/KL-Psychological-Methodology/ESMira/wiki'},
            {label: 'ESMira website', href: 'https://esmira.kl.ac.at/?about'},
          ],
        },
      ],
      copyright:
        'ESMira is developed by KL Psychological Methodology (AGPL-3.0). This site documents an independent fork. Built with Docusaurus.',
    },
    prism: {
      theme: prismThemes.github,
      darkTheme: prismThemes.dracula,
      additionalLanguages: ['php', 'bash', 'json', 'yaml', 'docker'],
    },
    mermaid: {
      theme: {light: 'neutral', dark: 'dark'},
    },
  } satisfies Preset.ThemeConfig,
};

export default config;
